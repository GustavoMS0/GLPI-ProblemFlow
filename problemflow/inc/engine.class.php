<?php

/**
 * ------------------------------------------------------------------------
 * Problem Flow - Motor de agrupamento de incidentes, gates RCA e sincronização
 *
 * Copyright (C) 2026 by G. Martins
 * ------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of Problem Flow.
 *
 * Problem Flow is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Problem Flow is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 * ------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

require_once __DIR__ . '/compat.class.php';
require_once __DIR__ . '/config.class.php';

class PluginProblemflowEngine
{
    /** @var bool */
    private static $escalating = false;

    /** @var array<int, bool> */
    private static $syncingProblems = [];

    /**
     * Reinicia o estado em memória entre cenários de teste.
     */
    public static function resetRuntimeState(): void
    {
        self::$escalating      = false;
        self::$syncingProblems = [];
    }

    /**
     * Hook item_add em Ticket: avalia recorrência e escalona/vincula Problema.
     *
     * @param CommonDBTM $item
     */
    public static function onTicketAdd(CommonDBTM $item): void
    {
        if (!($item instanceof Ticket)) {
            return;
        }
        self::evaluateTicketRecurrence($item);
    }

    /**
     * Hook item_update em Ticket: reavalia quando campos de agrupamento mudam.
     *
     * @param CommonDBTM $item
     */
    public static function onTicketUpdate(CommonDBTM $item): void
    {
        if (!($item instanceof Ticket)) {
            return;
        }

        $relevant = ['itilcategories_id', 'locations_id', 'entities_id', 'status', 'type'];
        $updates  = is_array($item->updates) ? $item->updates : [];
        if (!empty($updates) && empty(array_intersect($updates, $relevant))) {
            return;
        }

        self::evaluateTicketRecurrence($item);
    }

    /**
     * Hook pre_item_update em Problem: impõe gates RCA antes de solucionar ou fechar.
     *
     * @param CommonDBTM $item
     */
    public static function onPreProblemUpdate(CommonDBTM $item): void
    {
        if (!($item instanceof Problem)) {
            return;
        }
        if ($item->input === false || !is_array($item->input)) {
            return;
        }
        if (self::isBackgroundContext()) {
            return;
        }

        if (!array_key_exists('status', $item->input)) {
            return;
        }

        $newStatus = (int) $item->input['status'];
        $oldStatus = (int) ($item->fields['status'] ?? 0);

        if ($oldStatus === $newStatus) {
            return;
        }
        if (!in_array($newStatus, [PluginProblemflowConfigConstants::PROBLEM_STATUS_SOLVED, PluginProblemflowConfigConstants::PROBLEM_STATUS_CLOSED], true)) {
            return;
        }

        $entitiesId = (int) ($item->input['entities_id'] ?? $item->fields['entities_id'] ?? 0);
        $rule       = PluginProblemflowConfig::resolveForEntity($entitiesId);

        if ((int) ($rule['is_active'] ?? 0) !== 1) {
            return;
        }

        $evaluation = self::evaluateRcaCompleteness($item->fields, $rule, $item->input);
        if ($evaluation['valid']) {
            return;
        }

        $item->input = false;

        $missingLabels = [];
        foreach ($evaluation['failures'] as $failure) {
            $missingLabels[] = $failure['message'];
        }

        $errorMessage = sprintf(
            __('Não foi possível alterar o status do Problema: Análise de Causa Raiz (RCA) incompleta — %s.', 'problemflow'),
            implode('; ', $missingLabels)
        );

        if (class_exists('Session') && method_exists('Session', 'addMessageAfterRedirect')) {
            Session::addMessageAfterRedirect($errorMessage, true, ERROR);
        }

        PluginProblemflowConfig::logEvent([
            'entities_id' => $entitiesId,
            'problems_id' => (int) $item->getID(),
            'tickets_id'  => 0,
            'users_id'    => self::currentActorId(),
            'event_type'  => 'rca_blocked',
            'summary'     => $errorMessage,
            'details'     => implode(', ', array_keys($evaluation['failures'])),
        ]);
    }

    /**
     * Hook item_update em Problem: propaga solução para chamados vinculados ainda abertos.
     *
     * @param CommonDBTM $item
     */
    public static function onProblemUpdate(CommonDBTM $item): void
    {
        global $DB;

        if (!($item instanceof Problem)) {
            return;
        }

        $problemsId = (int) $item->getID();
        if ($problemsId <= 0 || isset(self::$syncingProblems[$problemsId])) {
            return;
        }

        $updates = is_array($item->updates) ? $item->updates : [];
        if (!empty($updates) && !in_array('status', $updates, true)) {
            return;
        }

        $newStatus = (int) ($item->fields['status'] ?? 0);
        $oldStatus = (int) ($item->oldvalues['status'] ?? 0);
        $terminal  = [
            PluginProblemflowConfigConstants::PROBLEM_STATUS_SOLVED,
            PluginProblemflowConfigConstants::PROBLEM_STATUS_CLOSED,
        ];

        if (!in_array($newStatus, $terminal, true) || in_array($oldStatus, $terminal, true)) {
            return;
        }

        $entitiesId = (int) ($item->fields['entities_id'] ?? 0);
        $rule       = PluginProblemflowConfig::resolveForEntity($entitiesId);
        if ((int) ($rule['is_active'] ?? 0) !== 1 || (int) ($rule['sync_solve_linked_tickets'] ?? 0) !== 1) {
            return;
        }

        if (!isset($DB) || !method_exists($DB, 'request')) {
            return;
        }

        self::$syncingProblems[$problemsId] = true;
        try {
            $linkedTicketIds = self::getLinkedTicketIds($problemsId);
            if (empty($linkedTicketIds)) {
                return;
            }

            $solutionText = self::buildSyncedSolutionText($item);
            $actorId      = self::currentActorId($item);
            $syncedIds    = [];

            foreach ($linkedTicketIds as $ticketsId) {
                $ticketRow = self::loadTicketRow($ticketsId);
                if ($ticketRow === null || !empty($ticketRow['is_deleted'])) {
                    continue;
                }
                $ticketStatus = (int) ($ticketRow['status'] ?? 1);
                if (in_array($ticketStatus, $terminal, true)) {
                    continue;
                }

                if (self::solveLinkedTicket($ticketsId, $solutionText, $actorId)) {
                    $syncedIds[] = $ticketsId;
                }
            }

            if (!empty($syncedIds)) {
                $summary = sprintf(
                    __('Solução do Problema #%1$d propagada para %2$d chamado(s): #%3$s.', 'problemflow'),
                    $problemsId,
                    count($syncedIds),
                    implode(', #', $syncedIds)
                );

                PluginProblemflowConfig::logEvent([
                    'entities_id' => $entitiesId,
                    'problems_id' => $problemsId,
                    'tickets_id'  => (int) $syncedIds[0],
                    'users_id'    => $actorId,
                    'event_type'  => 'tickets_synced',
                    'summary'     => $summary,
                    'details'     => implode(',', $syncedIds),
                ]);
            }
        } finally {
            unset(self::$syncingProblems[$problemsId]);
        }
    }

    /**
     * Limpeza de eventos ao expurgar um Ticket.
     *
     * @param CommonDBTM $item
     */
    public static function onTicketPurge(CommonDBTM $item): void
    {
        global $DB;

        if (!($item instanceof Ticket)) {
            return;
        }
        $ticketsId = (int) $item->getID();
        if ($ticketsId <= 0 || !isset($DB) || !method_exists($DB, 'delete')) {
            return;
        }
        if (method_exists($DB, 'tableExists') && !$DB->tableExists(PluginProblemflowConfigConstants::TABLE_EVENTS)) {
            return;
        }
        $DB->delete(PluginProblemflowConfigConstants::TABLE_EVENTS, ['tickets_id' => $ticketsId]);
    }

    /**
     * Limpeza de eventos ao expurgar um Problem.
     *
     * @param CommonDBTM $item
     */
    public static function onProblemPurge(CommonDBTM $item): void
    {
        global $DB;

        if (!($item instanceof Problem)) {
            return;
        }
        $problemsId = (int) $item->getID();
        if ($problemsId <= 0 || !isset($DB) || !method_exists($DB, 'delete')) {
            return;
        }
        if (method_exists($DB, 'tableExists') && !$DB->tableExists(PluginProblemflowConfigConstants::TABLE_EVENTS)) {
            return;
        }
        $DB->delete(PluginProblemflowConfigConstants::TABLE_EVENTS, ['problems_id' => $problemsId]);
    }

    /**
     * Avalia completude dos campos de Análise de Causa Raiz (RCA) de um Problema.
     *
     * @param array<string, mixed>      $fields
     * @param array<string, mixed>      $rule
     * @param array<string, mixed>|null $input
     * @return array<string, mixed>
     */
    public static function evaluateRcaCompleteness(array $fields, array $rule, ?array $input = null): array
    {
        $minLength = max(0, (int) ($rule['min_rca_length'] ?? 0));

        $descriptors = [
            'impact' => [
                'flag'  => 'require_impact',
                'field' => 'impactcontent',
                'label' => __('Impacto', 'problemflow'),
            ],
            'cause' => [
                'flag'  => 'require_cause',
                'field' => 'causecontent',
                'label' => __('Causa Raiz', 'problemflow'),
            ],
            'symptom' => [
                'flag'  => 'require_symptom',
                'field' => 'symptomcontent',
                'label' => __('Sintomas', 'problemflow'),
            ],
        ];

        $items    = [];
        $failures = [];

        foreach ($descriptors as $key => $meta) {
            $required  = (int) ($rule[$meta['flag']] ?? 0) === 1;
            $fieldName = $meta['field'];

            if (is_array($input) && array_key_exists($fieldName, $input)) {
                $decoded = PluginProblemflowCompat::fromSanitizedInput((string) $input[$fieldName]);
            } else {
                $decoded = PluginProblemflowCompat::fromCore((string) ($fields[$fieldName] ?? ''));
            }

            $length       = self::visibleLength($decoded);
            $neededLength = $required ? max(1, $minLength) : 0;
            $ok           = !$required || $length >= $neededLength;

            $items[$key] = [
                'field'         => $fieldName,
                'label'         => $meta['label'],
                'required'      => $required,
                'length'        => $length,
                'needed_length' => $neededLength,
                'ok'            => $ok,
            ];

            if (!$ok) {
                if ($length === 0) {
                    $msg = sprintf(__('%s não preenchido', 'problemflow'), $meta['label']);
                } else {
                    $msg = sprintf(
                        __('%1$s muito curto (%2$d/%3$d caracteres)', 'problemflow'),
                        $meta['label'],
                        $length,
                        $neededLength
                    );
                }
                $failures[$key] = [
                    'field'   => $fieldName,
                    'label'   => $meta['label'],
                    'length'  => $length,
                    'needed'  => $neededLength,
                    'message' => $msg,
                ];
            }
        }

        return [
            'valid'    => empty($failures),
            'items'    => $items,
            'failures' => $failures,
        ];
    }

    /**
     * Conta caracteres visíveis após remover HTML, entidades vazias (&nbsp;) e espaços repetidos.
     */
    public static function visibleLength(string $html): int
    {
        if ($html === '') {
            return 0;
        }

        $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain   = strip_tags($decoded);
        $plain   = str_replace(["\xC2\xA0", "\xE2\x80\x8B", "\xE2\x80\x8C", "\xE2\x80\x8D", "\xEF\xBB\xBF"], ' ', $plain);
        $plain   = (string) preg_replace('/\s+/u', ' ', $plain);
        $plain   = trim($plain);

        if ($plain === '') {
            return 0;
        }

        return function_exists('mb_strlen') ? mb_strlen($plain, 'UTF-8') : strlen($plain);
    }

    /**
     * Executa o agrupamento de chamados recorrentes e cria/vincula Problema quando o limiar é atingido.
     */
    private static function evaluateTicketRecurrence(Ticket $ticket): void
    {
        if (self::$escalating) {
            return;
        }

        $ticketId = (int) $ticket->getID();
        if ($ticketId <= 0) {
            return;
        }

        $fields = (array) $ticket->fields;
        if (!empty($fields['is_deleted'])) {
            return;
        }

        $status = (int) ($fields['status'] ?? 1);
        if (in_array($status, [5, 6], true)) {
            return;
        }

        $entitiesId = (int) ($fields['entities_id'] ?? 0);
        $rule       = PluginProblemflowConfig::resolveForEntity($entitiesId);

        if ((int) ($rule['is_active'] ?? 0) !== 1 || (int) ($rule['auto_escalate'] ?? 0) !== 1) {
            return;
        }

        if (self::isTicketLinkedToProblem($ticketId)) {
            return;
        }

        $matchCategory = (int) ($rule['match_category'] ?? 1) === 1;
        $matchLocation = (int) ($rule['match_location'] ?? 0) === 1;
        $matchItem     = (int) ($rule['match_item'] ?? 0) === 1;

        $catId = (int) ($fields['itilcategories_id'] ?? 0);
        $locId = (int) ($fields['locations_id'] ?? 0);

        if ($matchCategory && $catId <= 0) {
            return;
        }
        if ($matchLocation && $locId <= 0) {
            return;
        }

        $ticketItemKeys = [];
        if ($matchItem) {
            $ticketItemKeys = self::getTicketItemKeys($ticketId);
            if (empty($ticketItemKeys)) {
                return;
            }
        }

        $windowHours     = max(1, (int) ($rule['window_hours'] ?? 24));
        $threshold       = max(2, (int) ($rule['threshold_count'] ?? 3));
        $matchingTickets = self::findMatchingRecentTickets(
            $entitiesId,
            $matchCategory ? $catId : 0,
            $matchLocation ? $locId : 0,
            $ticketItemKeys,
            $windowHours
        );

        if (!isset($matchingTickets[$ticketId])) {
            $matchingTickets[$ticketId] = $fields;
        }

        if (count($matchingTickets) < $threshold) {
            return;
        }

        $matchingIds = array_keys($matchingTickets);
        $problemsId  = self::findOpenMatchingProblem($entitiesId, $matchCategory ? $catId : 0, $matchingIds);
        $createdNew  = false;

        self::$escalating = true;
        try {
            if ($problemsId <= 0) {
                $problemsId = self::createProblemForRecurrence(
                    $entitiesId,
                    $matchCategory ? $catId : 0,
                    $rule,
                    $matchingTickets
                );
                $createdNew = $problemsId > 0;
            }

            if ($problemsId <= 0) {
                return;
            }

            $newlyLinked = [];
            foreach ($matchingIds as $tid) {
                if (self::isTicketLinkedToProblem($tid)) {
                    continue;
                }
                if (self::linkTicketToProblem($problemsId, $tid)) {
                    $newlyLinked[] = $tid;
                }
            }

            if (!empty($newlyLinked)) {
                PluginProblemflowConfig::logEvent([
                    'entities_id' => $entitiesId,
                    'problems_id' => $problemsId,
                    'tickets_id'  => $ticketId,
                    'users_id'    => self::currentActorId($ticket),
                    'event_type'  => 'ticket_linked',
                    'summary'     => sprintf(
                        __('Chamados vinculados automaticamente ao Problema #%1$d: #%2$s.', 'problemflow'),
                        $problemsId,
                        implode(', #', $newlyLinked)
                    ),
                    'details'     => implode(',', $newlyLinked),
                ]);

                if (!self::isBackgroundContext() && class_exists('Session') && method_exists('Session', 'addMessageAfterRedirect')) {
                    $notice = $createdNew
                        ? sprintf(
                            __('Problem Flow: Problema #%1$d criado automaticamente para %2$d incidentes recorrentes.', 'problemflow'),
                            $problemsId,
                            count($matchingTickets)
                        )
                        : sprintf(
                            __('Problem Flow: chamado vinculado ao Problema existente #%d.', 'problemflow'),
                            $problemsId
                        );
                    Session::addMessageAfterRedirect($notice, true, INFO);
                }
            }
        } finally {
            self::$escalating = false;
        }
    }

    /**
     * Busca chamados abertos equivalentes dentro da janela configurada.
     *
     * @param array<int, string> $requiredItemKeys
     * @return array<int, array<string, mixed>>
     */
    private static function findMatchingRecentTickets(
        int $entitiesId,
        int $catId,
        int $locId,
        array $requiredItemKeys,
        int $windowHours
    ): array {
        global $DB;

        if (!isset($DB) || !method_exists($DB, 'request')) {
            return [];
        }

        $where = [
            'entities_id' => $entitiesId,
            'is_deleted'  => 0,
            'status'      => [1, 2, 3, 4],
        ];
        if ($catId > 0) {
            $where['itilcategories_id'] = $catId;
        }
        if ($locId > 0) {
            $where['locations_id'] = $locId;
        }

        $it = $DB->request([
            'FROM'  => 'glpi_tickets',
            'WHERE' => $where,
            'ORDER' => ['id DESC'],
            'LIMIT' => 500,
        ]);

        $cutoffTs = time() - ($windowHours * 3600);
        $matched  = [];

        foreach ($it as $row) {
            $tid = (int) ($row['id'] ?? 0);
            if ($tid <= 0) {
                continue;
            }

            $dateStr = (string) ($row['date_creation'] ?? ($row['date'] ?? ''));
            if ($dateStr !== '') {
                $ts = strtotime($dateStr);
                if ($ts !== false && $ts < $cutoffTs) {
                    continue;
                }
            }

            if (!empty($requiredItemKeys)) {
                $candidateKeys = self::getTicketItemKeys($tid);
                if (empty(array_intersect($requiredItemKeys, $candidateKeys))) {
                    continue;
                }
            }

            $matched[$tid] = $row;
        }

        return $matched;
    }

    /**
     * Retorna chaves "itemtype:items_id" associadas a um chamado em glpi_items_tickets.
     *
     * @return array<int, string>
     */
    private static function getTicketItemKeys(int $ticketsId): array
    {
        global $DB;

        if ($ticketsId <= 0 || !isset($DB) || !method_exists($DB, 'request')) {
            return [];
        }
        if (method_exists($DB, 'tableExists') && !$DB->tableExists('glpi_items_tickets')) {
            return [];
        }

        $it = $DB->request([
            'SELECT' => ['itemtype', 'items_id'],
            'FROM'   => 'glpi_items_tickets',
            'WHERE'  => ['tickets_id' => $ticketsId],
        ]);

        $keys = [];
        foreach ($it as $row) {
            $type = trim((string) ($row['itemtype'] ?? ''));
            $id   = (int) ($row['items_id'] ?? 0);
            if ($type !== '' && $id > 0) {
                $keys[] = $type . ':' . $id;
            }
        }

        return $keys;
    }

    /**
     * Localiza um Problema aberto equivalente (já vinculado a um dos chamados ou criado pelo ProblemFlow).
     *
     * @param array<int, int> $matchingTicketIds
     */
    private static function findOpenMatchingProblem(int $entitiesId, int $catId, array $matchingTicketIds): int
    {
        global $DB;

        if (!isset($DB) || !method_exists($DB, 'request')) {
            return 0;
        }

        $openStatuses = [
            PluginProblemflowConfigConstants::PROBLEM_STATUS_INCOMING,
            PluginProblemflowConfigConstants::PROBLEM_STATUS_ASSIGNED,
            PluginProblemflowConfigConstants::PROBLEM_STATUS_PLANNED,
            PluginProblemflowConfigConstants::PROBLEM_STATUS_WAITING,
        ];

        // 1) Verifica se algum dos chamados equivalentes já está vinculado a um Problema aberto
        if (!empty($matchingTicketIds) && (!method_exists($DB, 'tableExists') || $DB->tableExists('glpi_problems_tickets'))) {
            $links = $DB->request([
                'SELECT' => ['problems_id'],
                'FROM'   => 'glpi_problems_tickets',
                'WHERE'  => ['tickets_id' => $matchingTicketIds],
                'ORDER'  => ['id DESC'],
            ]);

            $checked = [];
            foreach ($links as $link) {
                $pid = (int) ($link['problems_id'] ?? 0);
                if ($pid <= 0 || isset($checked[$pid])) {
                    continue;
                }
                $checked[$pid] = true;

                $pIt = $DB->request([
                    'FROM'  => 'glpi_problems',
                    'WHERE' => [
                        'id'          => $pid,
                        'entities_id' => $entitiesId,
                        'is_deleted'  => 0,
                        'status'      => $openStatuses,
                    ],
                    'LIMIT' => 1,
                ]);
                foreach ($pIt as $problemRow) {
                    if ($catId > 0 && self::problemHasCategoryColumn()) {
                        if ((int) ($problemRow['itilcategories_id'] ?? 0) !== $catId) {
                            continue;
                        }
                    }
                    return (int) $problemRow['id'];
                }
            }
        }

        // 2) Busca Problema aberto gerado pelo ProblemFlow com mesma entidade e categoria
        $where = [
            'entities_id' => $entitiesId,
            'is_deleted'  => 0,
            'status'      => $openStatuses,
        ];
        if ($catId > 0 && self::problemHasCategoryColumn()) {
            $where['itilcategories_id'] = $catId;
        }

        $it = $DB->request([
            'FROM'  => 'glpi_problems',
            'WHERE' => $where,
            'ORDER' => ['id DESC'],
            'LIMIT' => 50,
        ]);

        foreach ($it as $row) {
            $name = PluginProblemflowCompat::fromCore((string) ($row['name'] ?? ''));
            if (strpos($name, '[ProblemFlow]') === 0) {
                return (int) $row['id'];
            }
        }

        return 0;
    }

    /**
     * Cria um novo Problema para consolidar incidentes recorrentes.
     *
     * @param array<string, mixed>              $rule
     * @param array<int, array<string, mixed>>  $matchingTickets
     */
    private static function createProblemForRecurrence(
        int $entitiesId,
        int $catId,
        array $rule,
        array $matchingTickets
    ): int {
        if (!class_exists('Problem')) {
            return 0;
        }

        $catName = '';
        if ($catId > 0 && class_exists('Dropdown') && method_exists('Dropdown', 'getDropdownName')) {
            $rawCat = (string) Dropdown::getDropdownName('glpi_itilcategories', $catId);
            $catName = trim(PluginProblemflowCompat::fromCore($rawCat));
            if ($catName === '&nbsp;' || $catName === '') {
                $catName = '';
            }
        }

        $titleSuffix = $catName !== '' ? (' - ' . $catName) : '';
        $title       = '[ProblemFlow] '
            . sprintf(__('Incidente recorrente (%d chamados)', 'problemflow'), count($matchingTickets))
            . $titleSuffix;

        if (function_exists('mb_substr')) {
            $title = mb_substr($title, 0, 250, 'UTF-8');
        } else {
            $title = substr($title, 0, 250);
        }

        $lines = [
            sprintf(
                __('Problema aberto automaticamente pelo Problem Flow após detecção de %1$d incidentes na janela de %2$dh.', 'problemflow'),
                count($matchingTickets),
                (int) ($rule['window_hours'] ?? 24)
            ),
        ];
        if ($catName !== '') {
            $lines[] = __('Categoria:', 'problemflow') . ' ' . $catName;
        }
        $lines[] = __('Chamados iniciais identificados:', 'problemflow');

        $listed = 0;
        foreach ($matchingTickets as $tid => $trow) {
            if ($listed >= 15) {
                $lines[] = '...';
                break;
            }
            $tName   = trim(PluginProblemflowCompat::fromCore((string) ($trow['name'] ?? '')));
            $lines[] = '- #' . (int) $tid . ($tName !== '' ? (' ' . $tName) : '');
            $listed++;
        }

        $initialStatus = (int) ($rule['problem_initial_status'] ?? PluginProblemflowConfigConstants::PROBLEM_STATUS_INCOMING);
        if (!in_array($initialStatus, [PluginProblemflowConfigConstants::PROBLEM_STATUS_INCOMING, PluginProblemflowConfigConstants::PROBLEM_STATUS_ASSIGNED], true)) {
            $initialStatus = PluginProblemflowConfigConstants::PROBLEM_STATUS_INCOMING;
        }

        $payload = [
            'name'               => $title,
            'content'            => implode("\n", $lines),
            'entities_id'        => $entitiesId,
            'status'             => $initialStatus,
            'users_id_recipient' => self::currentActorId(),
        ];
        if ($catId > 0 && self::problemHasCategoryColumn()) {
            $payload['itilcategories_id'] = $catId;
        }

        $problem    = new Problem();
        $problemsId = (int) $problem->add(PluginProblemflowCompat::coreInput($payload));

        if ($problemsId > 0) {
            PluginProblemflowConfig::logEvent([
                'entities_id' => $entitiesId,
                'problems_id' => $problemsId,
                'tickets_id'  => (int) array_key_first($matchingTickets),
                'users_id'    => self::currentActorId(),
                'event_type'  => 'problem_created',
                'summary'     => sprintf(
                    __('Problema #%1$d criado automaticamente (%2$d incidentes em %3$dh).', 'problemflow'),
                    $problemsId,
                    count($matchingTickets),
                    (int) ($rule['window_hours'] ?? 24)
                ),
                'details'     => $title,
            ]);
        }

        return $problemsId;
    }

    /**
     * Verifica se o chamado já possui vínculo em glpi_problems_tickets (com qualquer Problema ou específico).
     */
    private static function isTicketLinkedToProblem(int $ticketsId, int $problemsId = 0): bool
    {
        global $DB;

        if ($ticketsId <= 0 || !isset($DB) || !method_exists($DB, 'request')) {
            return false;
        }
        if (method_exists($DB, 'tableExists') && !$DB->tableExists('glpi_problems_tickets')) {
            return false;
        }

        $where = ['tickets_id' => $ticketsId];
        if ($problemsId > 0) {
            $where['problems_id'] = $problemsId;
        }

        $it = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_problems_tickets',
            'WHERE'  => $where,
            'LIMIT'  => 1,
        ]);

        foreach ($it as $row) {
            return true;
        }

        return false;
    }

    /**
     * Cria vínculo único em glpi_problems_tickets via Problem_Ticket::add().
     */
    private static function linkTicketToProblem(int $problemsId, int $ticketsId): bool
    {
        if ($problemsId <= 0 || $ticketsId <= 0) {
            return false;
        }
        if (self::isTicketLinkedToProblem($ticketsId, $problemsId)) {
            return false;
        }

        if (class_exists('Problem_Ticket')) {
            $link = new Problem_Ticket();
            $id   = (int) $link->add(PluginProblemflowCompat::coreInput([
                'problems_id' => $problemsId,
                'tickets_id'  => $ticketsId,
            ]));
            return $id > 0;
        }

        global $DB;
        if (isset($DB) && method_exists($DB, 'insert')) {
            return (bool) $DB->insert('glpi_problems_tickets', [
                'problems_id' => $problemsId,
                'tickets_id'  => $ticketsId,
            ]);
        }

        return false;
    }

    /**
     * Verifica se a tabela glpi_problems possui a coluna itilcategories_id.
     */
    private static function problemHasCategoryColumn(): bool
    {
        global $DB;

        if (isset($DB) && method_exists($DB, 'fieldExists')) {
            return (bool) $DB->fieldExists('glpi_problems', 'itilcategories_id');
        }
        return true;
    }

    /**
     * Lista os IDs de chamados vinculados a um Problema em glpi_problems_tickets.
     *
     * @return array<int, int>
     */
    private static function getLinkedTicketIds(int $problemsId): array
    {
        global $DB;

        if ($problemsId <= 0 || !isset($DB) || !method_exists($DB, 'request')) {
            return [];
        }
        if (method_exists($DB, 'tableExists') && !$DB->tableExists('glpi_problems_tickets')) {
            return [];
        }

        $it = $DB->request([
            'SELECT' => ['tickets_id'],
            'FROM'   => 'glpi_problems_tickets',
            'WHERE'  => ['problems_id' => $problemsId],
            'ORDER'  => ['id ASC'],
        ]);

        $ids = [];
        foreach ($it as $row) {
            $tid = (int) ($row['tickets_id'] ?? 0);
            if ($tid > 0 && !in_array($tid, $ids, true)) {
                $ids[] = $tid;
            }
        }
        return $ids;
    }

    /**
     * Carrega os dados básicos de um chamado em glpi_tickets.
     *
     * @return array<string, mixed>|null
     */
    private static function loadTicketRow(int $ticketsId): ?array
    {
        global $DB;

        if ($ticketsId <= 0 || !isset($DB) || !method_exists($DB, 'request')) {
            return null;
        }

        $it = $DB->request([
            'FROM'  => 'glpi_tickets',
            'WHERE' => ['id' => $ticketsId],
            'LIMIT' => 1,
        ]);

        foreach ($it as $row) {
            return $row;
        }
        return null;
    }

    /**
     * Monta o texto limpo de solução para os chamados vinculados.
     */
    private static function buildSyncedSolutionText(Problem $problem): string
    {
        global $DB;

        $problemsId  = (int) $problem->getID();
        $problemName = trim(PluginProblemflowCompat::fromCore((string) ($problem->fields['name'] ?? '')));
        $header      = sprintf(__('Resolvido automaticamente pelo Problema #%1$d: %2$s', 'problemflow'), $problemsId, $problemName);

        $extra = '';
        if (isset($DB) && method_exists($DB, 'request') && (!method_exists($DB, 'tableExists') || $DB->tableExists('glpi_itilsolutions'))) {
            $solIt = $DB->request([
                'FROM'  => 'glpi_itilsolutions',
                'WHERE' => [
                    'itemtype' => 'Problem',
                    'items_id' => $problemsId,
                ],
                'ORDER' => ['id DESC'],
                'LIMIT' => 1,
            ]);
            foreach ($solIt as $solRow) {
                $decoded = PluginProblemflowCompat::fromCore((string) ($solRow['content'] ?? ''));
                if (self::visibleLength($decoded) > 0) {
                    $extra = trim($decoded);
                }
                break;
            }
        }

        if ($extra === '') {
            $cause = PluginProblemflowCompat::fromCore((string) ($problem->fields['causecontent'] ?? ''));
            if (self::visibleLength($cause) > 0) {
                $extra = __('Causa Raiz:', 'problemflow') . ' ' . trim(strip_tags(html_entity_decode($cause, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            }
        }

        return $extra !== '' ? ($header . "\n\n" . $extra) : $header;
    }

    /**
     * Aplica a solução e garante transição para Solucionado em um chamado vinculado aberto.
     */
    private static function solveLinkedTicket(int $ticketsId, string $solutionText, int $actorId): bool
    {
        $applied = false;

        if (class_exists('ITILSolution')) {
            $solution = new ITILSolution();
            $solId    = (int) $solution->add(PluginProblemflowCompat::coreInput([
                'itemtype' => 'Ticket',
                'items_id' => $ticketsId,
                'content'  => $solutionText,
                'users_id' => $actorId,
                'status'   => 2,
            ]));
            if ($solId > 0) {
                $applied = true;
            }
        }

        $afterRow = self::loadTicketRow($ticketsId);
        $afterSt  = (int) ($afterRow['status'] ?? 1);

        if (!in_array($afterSt, [5, 6], true) && class_exists('Ticket')) {
            $ticket = new Ticket();
            $ok     = (bool) $ticket->update(PluginProblemflowCompat::coreInput([
                'id'     => $ticketsId,
                'status' => 5,
            ]));
            if ($ok) {
                $applied = true;
            }
        }

        return $applied;
    }

    /**
     * Indica se o contexto atual é CLI ou Cron automático do GLPI.
     */
    private static function isBackgroundContext(): bool
    {
        if (function_exists('isCommandLineInterface') && isCommandLineInterface()) {
            return true;
        }
        if (class_exists('Session') && method_exists('Session', 'isCron') && Session::isCron()) {
            return true;
        }
        return false;
    }

    /**
     * Retorna o ID do usuário autenticado ou o autor do item como fallback.
     */
    private static function currentActorId(?CommonDBTM $item = null): int
    {
        if (class_exists('Session') && method_exists('Session', 'getLoginUserID')) {
            $uid = (int) Session::getLoginUserID(true);
            if ($uid > 0) {
                return $uid;
            }
        }
        if ($item !== null && isset($item->fields['users_id_recipient'])) {
            return max(0, (int) $item->fields['users_id_recipient']);
        }
        return 0;
    }
}

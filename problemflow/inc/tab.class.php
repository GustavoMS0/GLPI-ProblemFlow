<?php

/**
 * ------------------------------------------------------------------------
 * Problem Flow - Aba "Problem Flow" em Problemas (Problem)
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
require_once __DIR__ . '/engine.class.php';

interface PluginProblemflowTabConstants
{
    public const TAB_NUM = 1;
}

trait PluginProblemflowTabBody
{
    /**
     * Nome da aba exibido no GLPI.
     *
     * @param int $nb
     */
    public static function getTypeName($nb = 0): string
    {
        return __('Problem Flow', 'problemflow');
    }

    /**
     * Verifica direito de leitura na classe Problem.
     */
    public static function canView(): bool
    {
        return (bool) Session::haveRight(self::$rightname, READ);
    }

    private static function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Valida sessão autenticada, permissão no item e acesso à entidade do Problema.
     *
     * @param int $withtemplate
     */
    private static function canAccessProblem(CommonGLPI $item, $withtemplate = 0): bool
    {
        if ($withtemplate || !($item instanceof Problem)) {
            return false;
        }
        $problemsId = (int) $item->getID();
        if ($problemsId <= 0) {
            return false;
        }
        if ((int) Session::getLoginUserID(true) <= 0) {
            return false;
        }
        if (!$item->canViewItem()) {
            return false;
        }
        if (
            method_exists($item, 'getEntityID')
            && method_exists('Session', 'haveAccessToEntity')
            && !Session::haveAccessToEntity((int) $item->getEntityID())
        ) {
            return false;
        }

        return true;
    }

    /**
     * Conta quantos chamados estão vinculados ao Problema.
     */
    private static function countLinkedTickets(int $problemsId): int
    {
        global $DB;

        if ($problemsId <= 0 || !isset($DB) || !method_exists($DB, 'request')) {
            return 0;
        }
        if (method_exists($DB, 'tableExists') && !$DB->tableExists('glpi_problems_tickets')) {
            return 0;
        }

        $it = $DB->request([
            'SELECT' => ['tickets_id'],
            'FROM'   => 'glpi_problems_tickets',
            'WHERE'  => ['problems_id' => $problemsId],
        ]);

        $seen = [];
        foreach ($it as $row) {
            $tid = (int) ($row['tickets_id'] ?? 0);
            if ($tid > 0) {
                $seen[$tid] = true;
            }
        }
        return count($seen);
    }

    /**
     * Carrega os chamados vinculados ao Problema.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function getLinkedTickets(int $problemsId): array
    {
        global $DB;

        if ($problemsId <= 0 || !isset($DB) || !method_exists($DB, 'request')) {
            return [];
        }
        if (method_exists($DB, 'tableExists') && !$DB->tableExists('glpi_problems_tickets')) {
            return [];
        }

        $links = $DB->request([
            'SELECT' => ['tickets_id'],
            'FROM'   => 'glpi_problems_tickets',
            'WHERE'  => ['problems_id' => $problemsId],
            'ORDER'  => ['id DESC'],
        ]);

        $ticketIds = [];
        foreach ($links as $row) {
            $tid = (int) ($row['tickets_id'] ?? 0);
            if ($tid > 0 && !in_array($tid, $ticketIds, true)) {
                $ticketIds[] = $tid;
            }
        }
        if (empty($ticketIds)) {
            return [];
        }

        $tickets = [];
        $it      = $DB->request([
            'FROM'  => 'glpi_tickets',
            'WHERE' => ['id' => $ticketIds],
            'ORDER' => ['id DESC'],
        ]);
        foreach ($it as $row) {
            $tickets[] = $row;
        }

        return $tickets;
    }

    /**
     * Traduz código de status de Ticket em rótulo legível.
     */
    private static function ticketStatusLabel(int $status): string
    {
        if (class_exists('Ticket') && method_exists('Ticket', 'getStatus')) {
            return (string) Ticket::getStatus($status);
        }

        $map = [
            1 => __('Novo', 'problemflow'),
            2 => __('Em atendimento (atribuído)', 'problemflow'),
            3 => __('Em atendimento (planejado)', 'problemflow'),
            4 => __('Pendente', 'problemflow'),
            5 => __('Solucionado', 'problemflow'),
            6 => __('Fechado', 'problemflow'),
        ];
        return $map[$status] ?? ('#' . $status);
    }

    /**
     * Retorna o título da aba com contador de chamados vinculados.
     *
     * @param int $withtemplate
     * @return string|array<int, string>
     */
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!self::canAccessProblem($item, $withtemplate)) {
            return '';
        }

        $count = self::countLinkedTickets((int) $item->getID());
        return self::createTabEntry(__('Problem Flow', 'problemflow'), $count);
    }

    /**
     * Renderiza o painel RCA, regra da entidade, incidentes vinculados e eventos.
     *
     * @param int $tabnum
     * @param int $withtemplate
     */
    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if (!self::canAccessProblem($item, $withtemplate)) {
            return false;
        }

        global $CFG_GLPI;

        $problemsId = (int) $item->getID();
        $entitiesId = method_exists($item, 'getEntityID')
            ? (int) $item->getEntityID()
            : (int) ($item->fields['entities_id'] ?? 0);

        $rule          = PluginProblemflowConfig::resolveForEntity($entitiesId);
        $rca           = PluginProblemflowEngine::evaluateRcaCompleteness((array) $item->fields, $rule, null);
        $linkedTickets = self::getLinkedTickets($problemsId);
        $events        = PluginProblemflowConfig::getEventsForProblem($problemsId, 30);
        $rootDoc       = isset($CFG_GLPI['root_doc']) ? rtrim((string) $CFG_GLPI['root_doc'], '/') : '';

        echo '<div class="problemflow-tab">';

        // Bloco 1: Checklist RCA
        echo '<div class="problemflow-card">';
        echo '<div class="problemflow-card-header">';
        echo '<h3 class="problemflow-section-title"><i class="ti ti-clipboard-check" aria-hidden="true"></i> '
            . self::escapeHtml(__('Checklist de Análise de Causa Raiz (RCA)', 'problemflow'))
            . '</h3>';
        if ((int) ($rule['is_active'] ?? 0) === 1) {
            $gateBadgeClass = $rca['valid'] ? 'problemflow-badge-ok' : 'problemflow-badge-warn';
            $gateBadgeText  = $rca['valid']
                ? __('RCA pronta para solução/fechamento', 'problemflow')
                : __('Campos RCA obrigatórios pendentes', 'problemflow');
        } else {
            $gateBadgeClass = 'problemflow-badge-muted';
            $gateBadgeText  = __('Política inativa nesta entidade', 'problemflow');
        }
        echo '<span class="problemflow-badge ' . self::escapeHtml($gateBadgeClass) . '">'
            . self::escapeHtml($gateBadgeText)
            . '</span>';
        echo '</div>';

        echo '<div class="problemflow-rca-grid">';
        foreach ($rca['items'] as $entry) {
            $isRequired = !empty($entry['required']);
            $isOk       = !empty($entry['ok']);
            $len        = (int) ($entry['length'] ?? 0);
            $needed     = (int) ($entry['needed_length'] ?? 0);

            if ($isRequired && !$isOk) {
                $statusClass = 'problemflow-rca-missing';
                $statusLabel = sprintf(__('Pendente (%1$d/%2$d chars)', 'problemflow'), $len, $needed);
            } elseif ($isRequired) {
                $statusClass = 'problemflow-rca-complete';
                $statusLabel = sprintf(__('Completo (%d chars)', 'problemflow'), $len);
            } else {
                $statusClass = 'problemflow-rca-optional';
                $statusLabel = sprintf(__('Opcional (%d chars)', 'problemflow'), $len);
            }

            echo '<div class="problemflow-rca-item ' . self::escapeHtml($statusClass) . '">';
            echo '<strong>' . self::escapeHtml((string) $entry['label']) . '</strong>';
            echo '<span>' . self::escapeHtml($statusLabel) . '</span>';
            echo '</div>';
        }
        echo '</div>';
        echo '</div>';

        // Bloco 2: Regra ativa na entidade
        echo '<div class="problemflow-card">';
        echo '<h3 class="problemflow-section-title"><i class="ti ti-settings-automation" aria-hidden="true"></i> '
            . self::escapeHtml(__('Regra Ativa na Entidade', 'problemflow'))
            . '</h3>';
        echo '<div class="problemflow-summary-grid">';

        $originLabel = __('Padrão do sistema (inativo)', 'problemflow');
        if ($rule['inherited_from'] !== null) {
            $parentId    = (int) $rule['inherited_from'];
            $parentName  = class_exists('Dropdown')
                ? PluginProblemflowCompat::fromCore((string) Dropdown::getDropdownName('glpi_entities', $parentId))
                : ('#' . $parentId);
            $originLabel = sprintf(__('Herdada de %s', 'problemflow'), $parentName);
        } elseif ((int) ($rule['id'] ?? 0) > 0) {
            $originLabel = __('Regra própria da entidade', 'problemflow');
        }

        $criteriaParts = [];
        if (!empty($rule['match_category'])) {
            $criteriaParts[] = __('Categoria', 'problemflow');
        }
        if (!empty($rule['match_location'])) {
            $criteriaParts[] = __('Localização', 'problemflow');
        }
        if (!empty($rule['match_item'])) {
            $criteriaParts[] = __('Ativo vinculado', 'problemflow');
        }
        $criteriaText = !empty($criteriaParts) ? implode(' + ', $criteriaParts) : __('Entidade', 'problemflow');

        $summaryItems = [
            __('Origem da política', 'problemflow') => $originLabel,
            __('Escalonamento automático', 'problemflow') => !empty($rule['auto_escalate'])
                ? sprintf(__('Ativo (%1$d chamados em %2$dh)', 'problemflow'), (int) $rule['threshold_count'], (int) $rule['window_hours'])
                : __('Desativado', 'problemflow'),
            __('Chave de agrupamento', 'problemflow') => $criteriaText,
            __('Sincronizar fechamento', 'problemflow') => !empty($rule['sync_solve_linked_tickets'])
                ? __('Sim — propaga solução para chamados abertos', 'problemflow')
                : __('Não', 'problemflow'),
        ];

        foreach ($summaryItems as $k => $v) {
            echo '<div class="problemflow-summary-item">';
            echo '<span class="problemflow-summary-label">' . self::escapeHtml($k) . '</span>';
            echo '<span class="problemflow-summary-value">' . self::escapeHtml($v) . '</span>';
            echo '</div>';
        }
        echo '</div>';
        echo '</div>';

        // Bloco 3: Incidentes recorrentes vinculados
        echo '<div class="problemflow-card">';
        echo '<div class="problemflow-card-header">';
        echo '<h3 class="problemflow-section-title"><i class="ti ti-git-merge" aria-hidden="true"></i> '
            . self::escapeHtml(__('Incidentes Recorrentes Vinculados', 'problemflow'))
            . '</h3>';
        echo '<span class="problemflow-badge problemflow-badge-muted">'
            . self::escapeHtml((string) count($linkedTickets))
            . '</span>';
        echo '</div>';

        if (empty($linkedTickets)) {
            echo '<p class="text-muted mb-0">'
                . self::escapeHtml(__('Nenhum chamado vinculado a este Problema até o momento.', 'problemflow'))
                . '</p>';
        } else {
            echo '<div class="table-responsive">';
            echo '<table class="table table-hover table-striped mb-0 problemflow-table">';
            echo '<thead><tr>';
            echo '<th scope="col">' . self::escapeHtml(__('ID', 'problemflow')) . '</th>';
            echo '<th scope="col">' . self::escapeHtml(__('Título', 'problemflow')) . '</th>';
            echo '<th scope="col">' . self::escapeHtml(__('Status', 'problemflow')) . '</th>';
            echo '<th scope="col">' . self::escapeHtml(__('Abertura', 'problemflow')) . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($linkedTickets as $trow) {
                $tid   = (int) ($trow['id'] ?? 0);
                $tname = trim(PluginProblemflowCompat::fromCore((string) ($trow['name'] ?? '')));
                $tUrl  = (class_exists('Ticket') && method_exists('Ticket', 'getFormURLWithID'))
                    ? (string) Ticket::getFormURLWithID($tid)
                    : ($rootDoc . '/front/ticket.form.php?id=' . $tid);
                $statusText = self::ticketStatusLabel((int) ($trow['status'] ?? 1));
                $dateRaw    = (string) ($trow['date_creation'] ?? ($trow['date'] ?? ''));
                $dateText   = ($dateRaw !== '' && class_exists('Html') && method_exists('Html', 'convDateTime'))
                    ? (string) Html::convDateTime($dateRaw)
                    : ($dateRaw !== '' ? $dateRaw : '-');

                echo '<tr>';
                echo '<td><a href="' . self::escapeHtml($tUrl) . '">#' . self::escapeHtml((string) $tid) . '</a></td>';
                echo '<td><a href="' . self::escapeHtml($tUrl) . '">' . self::escapeHtml($tname !== '' ? $tname : ('#' . $tid)) . '</a></td>';
                echo '<td>' . self::escapeHtml($statusText) . '</td>';
                echo '<td>' . self::escapeHtml($dateText) . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table></div>';
        }
        echo '</div>';

        // Bloco 4: Histórico ProblemFlow
        echo '<div class="problemflow-card">';
        echo '<h3 class="problemflow-section-title"><i class="ti ti-history" aria-hidden="true"></i> '
            . self::escapeHtml(__('Histórico Problem Flow', 'problemflow'))
            . '</h3>';

        if (empty($events)) {
            echo '<p class="text-muted mb-0">'
                . self::escapeHtml(__('Nenhum evento registrado para este Problema.', 'problemflow'))
                . '</p>';
        } else {
            echo '<div class="table-responsive">';
            echo '<table class="table table-sm table-striped mb-0 problemflow-table">';
            echo '<thead><tr>';
            echo '<th scope="col">' . self::escapeHtml(__('Data', 'problemflow')) . '</th>';
            echo '<th scope="col">' . self::escapeHtml(__('Evento', 'problemflow')) . '</th>';
            echo '<th scope="col">' . self::escapeHtml(__('Resumo', 'problemflow')) . '</th>';
            echo '</tr></thead><tbody>';

            foreach ($events as $ev) {
                $dtRaw  = (string) ($ev['date_creation'] ?? '');
                $dtText = ($dtRaw !== '' && class_exists('Html') && method_exists('Html', 'convDateTime'))
                    ? (string) Html::convDateTime($dtRaw)
                    : ($dtRaw !== '' ? $dtRaw : '-');
                $type    = (string) ($ev['event_type'] ?? '');
                $summary = PluginProblemflowCompat::fromCore((string) ($ev['summary'] ?? ''));

                echo '<tr>';
                echo '<td>' . self::escapeHtml($dtText) . '</td>';
                echo '<td><code>' . self::escapeHtml($type) . '</code></td>';
                echo '<td>' . self::escapeHtml($summary) . '</td>';
                echo '</tr>';
            }

            echo '</tbody></table></div>';
        }
        echo '</div>';

        echo '</div>';
        return true;
    }
}

if (!class_exists('PluginProblemflowTab', false)) {
    if (PluginProblemflowCompat::typedRightname()) {
        class PluginProblemflowTab extends CommonGLPI implements PluginProblemflowTabConstants
        {
            use PluginProblemflowTabBody;

            public static string $rightname = 'problem';
        }
    } else {
        class PluginProblemflowTab extends CommonGLPI implements PluginProblemflowTabConstants
        {
            use PluginProblemflowTabBody;

            public static $rightname = 'problem';
        }
    }
}

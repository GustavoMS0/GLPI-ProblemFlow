<?php

/**
 * ------------------------------------------------------------------------
 * Problem Flow - Configuração por entidade e registro de eventos
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

interface PluginProblemflowConfigConstants
{
    public const TABLE        = 'glpi_plugin_problemflow_rules';
    public const TABLE_EVENTS = 'glpi_plugin_problemflow_events';

    public const PROBLEM_STATUS_INCOMING = 1;
    public const PROBLEM_STATUS_ASSIGNED = 2;
    public const PROBLEM_STATUS_PLANNED  = 3;
    public const PROBLEM_STATUS_WAITING  = 4;
    public const PROBLEM_STATUS_SOLVED   = 5;
    public const PROBLEM_STATUS_CLOSED   = 6;

    public const MIN_THRESHOLD     = 2;
    public const MAX_THRESHOLD     = 50;
    public const DEFAULT_THRESHOLD = 3;

    public const MIN_WINDOW_HOURS     = 1;
    public const MAX_WINDOW_HOURS     = 720;
    public const DEFAULT_WINDOW_HOURS = 24;

    public const MAX_RCA_LENGTH = 500;
}

trait PluginProblemflowConfigBody
{
    /**
     * Nome legível do item no GLPI.
     *
     * @param int $nb
     */
    public static function getTypeName($nb = 0): string
    {
        return __('Problem Flow', 'problemflow');
    }

    /**
     * Tabela principal de regras do plugin.
     *
     * @param string|null $classname
     */
    public static function getTable($classname = null): string
    {
        return self::TABLE;
    }

    /**
     * Cria as tabelas do plugin na instalação ou atualização.
     */
    public static function installSchema(): void
    {
        global $DB;

        if (!isset($DB) || !is_object($DB)) {
            return;
        }

        $charset   = class_exists('DBConnection') ? DBConnection::getDefaultCharset() : 'utf8mb4';
        $collation = class_exists('DBConnection') ? DBConnection::getDefaultCollation() : 'utf8mb4_unicode_ci';

        if (!method_exists($DB, 'tableExists') || !$DB->tableExists(self::TABLE)) {
            $sqlRules = "CREATE TABLE IF NOT EXISTS `" . self::TABLE . "` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `entities_id` int unsigned NOT NULL DEFAULT '0',
                `is_recursive` tinyint NOT NULL DEFAULT '1',
                `is_active` tinyint NOT NULL DEFAULT '0',
                `auto_escalate` tinyint NOT NULL DEFAULT '0',
                `threshold_count` int unsigned NOT NULL DEFAULT '3',
                `window_hours` int unsigned NOT NULL DEFAULT '24',
                `match_category` tinyint NOT NULL DEFAULT '1',
                `match_location` tinyint NOT NULL DEFAULT '0',
                `match_item` tinyint NOT NULL DEFAULT '0',
                `problem_initial_status` int unsigned NOT NULL DEFAULT '1',
                `require_impact` tinyint NOT NULL DEFAULT '0',
                `require_cause` tinyint NOT NULL DEFAULT '0',
                `require_symptom` tinyint NOT NULL DEFAULT '0',
                `min_rca_length` int unsigned NOT NULL DEFAULT '0',
                `sync_solve_linked_tickets` tinyint NOT NULL DEFAULT '0',
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_entities_id` (`entities_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC";
            PluginProblemflowCompat::execDdl($DB, $sqlRules);
        }

        if (!method_exists($DB, 'tableExists') || !$DB->tableExists(self::TABLE_EVENTS)) {
            $sqlEvents = "CREATE TABLE IF NOT EXISTS `" . self::TABLE_EVENTS . "` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `entities_id` int unsigned NOT NULL DEFAULT '0',
                `problems_id` int unsigned NOT NULL DEFAULT '0',
                `tickets_id` int unsigned NOT NULL DEFAULT '0',
                `users_id` int unsigned NOT NULL DEFAULT '0',
                `event_type` varchar(64) NOT NULL DEFAULT '',
                `summary` varchar(512) NOT NULL DEFAULT '',
                `details` text DEFAULT NULL,
                `date_creation` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_entities_id` (`entities_id`),
                KEY `idx_problems_id` (`problems_id`),
                KEY `idx_tickets_id` (`tickets_id`),
                KEY `idx_event_type` (`event_type`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC";
            PluginProblemflowCompat::execDdl($DB, $sqlEvents);
        }
    }

    /**
     * Remove as tabelas do plugin na desinstalação.
     */
    public static function uninstallSchema(): void
    {
        global $DB;

        if (!isset($DB) || !is_object($DB)) {
            return;
        }

        if (!method_exists($DB, 'tableExists') || $DB->tableExists(self::TABLE_EVENTS)) {
            PluginProblemflowCompat::execDdl($DB, 'DROP TABLE IF EXISTS `' . self::TABLE_EVENTS . '`');
        }
        if (!method_exists($DB, 'tableExists') || $DB->tableExists(self::TABLE)) {
            PluginProblemflowCompat::execDdl($DB, 'DROP TABLE IF EXISTS `' . self::TABLE . '`');
        }
    }

    /**
     * Configuração padrão segura (inativa) quando não há regra definida na árvore de entidades.
     *
     * @return array<string, mixed>
     */
    public static function defaultConfig(int $entities_id = 0): array
    {
        return [
            'id'                        => 0,
            'entities_id'               => $entities_id,
            'is_recursive'              => 1,
            'is_active'                 => 0,
            'auto_escalate'             => 0,
            'threshold_count'           => self::DEFAULT_THRESHOLD,
            'window_hours'              => self::DEFAULT_WINDOW_HOURS,
            'match_category'            => 1,
            'match_location'            => 0,
            'match_item'                => 0,
            'problem_initial_status'    => self::PROBLEM_STATUS_INCOMING,
            'require_impact'            => 0,
            'require_cause'             => 0,
            'require_symptom'           => 0,
            'min_rca_length'            => 0,
            'sync_solve_linked_tickets' => 0,
            'date_mod'                  => null,
            'inherited_from'            => null,
        ];
    }

    /**
     * Retorna a configuração própria da entidade, ou null se não houver registro direto.
     *
     * @return array<string, mixed>|null
     */
    public static function getOwnConfig(int $entities_id): ?array
    {
        global $DB;

        if (!isset($DB) || !method_exists($DB, 'request')) {
            return null;
        }
        if (method_exists($DB, 'tableExists') && !$DB->tableExists(self::TABLE)) {
            return null;
        }

        $it = $DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => ['entities_id' => $entities_id],
            'LIMIT' => 1,
        ]);

        foreach ($it as $row) {
            return self::normalizeRow($row);
        }

        return null;
    }

    /**
     * Resolve a regra efetiva para uma entidade:
     * 1) registro exato da entidade (mesmo com is_recursive = 0);
     * 2) primeiro ancestral (do pai mais próximo até 0) com is_recursive = 1;
     * 3) preset padrão inativo caso nenhum exista.
     *
     * @return array<string, mixed>
     */
    public static function resolveForEntity(int $entities_id): array
    {
        $own = self::getOwnConfig($entities_id);
        if ($own !== null) {
            return $own;
        }

        $ancestors = PluginProblemflowCompat::getEntitiesAncestorsFallback($entities_id);
        foreach ($ancestors as $ancestorId) {
            $row = self::getOwnConfig((int) $ancestorId);
            if ($row !== null && (int) ($row['is_recursive'] ?? 0) === 1) {
                $row['inherited_from'] = (int) $ancestorId;
                return $row;
            }
        }

        return self::defaultConfig($entities_id);
    }

    /**
     * Salva ou atualiza a configuração de uma entidade.
     *
     * @param array<string, mixed> $post
     */
    public static function saveConfig(int $entities_id, array $post): bool
    {
        global $DB;

        if ($entities_id < 0 || !isset($DB) || !method_exists($DB, 'insert')) {
            return false;
        }

        if (method_exists($DB, 'tableExists') && !$DB->tableExists(self::TABLE)) {
            self::installSchema();
        }

        $threshold = (int) ($post['threshold_count'] ?? self::DEFAULT_THRESHOLD);
        if ($threshold < self::MIN_THRESHOLD) {
            $threshold = self::MIN_THRESHOLD;
        } elseif ($threshold > self::MAX_THRESHOLD) {
            $threshold = self::MAX_THRESHOLD;
        }

        $windowHours = (int) ($post['window_hours'] ?? self::DEFAULT_WINDOW_HOURS);
        if ($windowHours < self::MIN_WINDOW_HOURS) {
            $windowHours = self::MIN_WINDOW_HOURS;
        } elseif ($windowHours > self::MAX_WINDOW_HOURS) {
            $windowHours = self::MAX_WINDOW_HOURS;
        }

        $initialStatus = (int) ($post['problem_initial_status'] ?? self::PROBLEM_STATUS_INCOMING);
        if (!in_array($initialStatus, [self::PROBLEM_STATUS_INCOMING, self::PROBLEM_STATUS_ASSIGNED], true)) {
            $initialStatus = self::PROBLEM_STATUS_INCOMING;
        }

        $minRcaLength = (int) ($post['min_rca_length'] ?? 0);
        if ($minRcaLength < 0) {
            $minRcaLength = 0;
        } elseif ($minRcaLength > self::MAX_RCA_LENGTH) {
            $minRcaLength = self::MAX_RCA_LENGTH;
        }

        $data = [
            'is_recursive'              => self::toBoolInt($post['is_recursive'] ?? 0),
            'is_active'                 => self::toBoolInt($post['is_active'] ?? 0),
            'auto_escalate'             => self::toBoolInt($post['auto_escalate'] ?? 0),
            'threshold_count'           => $threshold,
            'window_hours'              => $windowHours,
            'match_category'            => self::toBoolInt($post['match_category'] ?? 0),
            'match_location'            => self::toBoolInt($post['match_location'] ?? 0),
            'match_item'                => self::toBoolInt($post['match_item'] ?? 0),
            'problem_initial_status'    => $initialStatus,
            'require_impact'            => self::toBoolInt($post['require_impact'] ?? 0),
            'require_cause'             => self::toBoolInt($post['require_cause'] ?? 0),
            'require_symptom'           => self::toBoolInt($post['require_symptom'] ?? 0),
            'min_rca_length'            => $minRcaLength,
            'sync_solve_linked_tickets' => self::toBoolInt($post['sync_solve_linked_tickets'] ?? 0),
            'date_mod'                  => date('Y-m-d H:i:s'),
        ];

        $values   = PluginProblemflowCompat::dbValues($data);
        $existing = self::getOwnConfig($entities_id);

        if ($existing !== null) {
            return (bool) $DB->update(self::TABLE, $values, ['entities_id' => $entities_id]);
        }

        $values['entities_id'] = $entities_id;
        return (bool) $DB->insert(self::TABLE, $values);
    }

    /**
     * Remove a configuração específica de uma entidade para restaurar a herança.
     */
    public static function deleteConfig(int $entities_id): bool
    {
        global $DB;

        if (!isset($DB) || !method_exists($DB, 'delete')) {
            return false;
        }
        if (method_exists($DB, 'tableExists') && !$DB->tableExists(self::TABLE)) {
            return true;
        }

        return (bool) $DB->delete(self::TABLE, ['entities_id' => $entities_id]);
    }

    /**
     * Grava um evento de auditoria na tabela glpi_plugin_problemflow_events.
     *
     * @param array<string, mixed> $event
     */
    public static function logEvent(array $event): bool
    {
        global $DB;

        if (!isset($DB) || !method_exists($DB, 'insert')) {
            return false;
        }
        if (method_exists($DB, 'tableExists') && !$DB->tableExists(self::TABLE_EVENTS)) {
            return false;
        }

        $summary = (string) ($event['summary'] ?? '');
        if (function_exists('mb_substr')) {
            $summary = mb_substr($summary, 0, 500, 'UTF-8');
        } else {
            $summary = substr($summary, 0, 500);
        }

        $row = PluginProblemflowCompat::dbValues([
            'entities_id'   => max(0, (int) ($event['entities_id'] ?? 0)),
            'problems_id'   => max(0, (int) ($event['problems_id'] ?? 0)),
            'tickets_id'    => max(0, (int) ($event['tickets_id'] ?? 0)),
            'users_id'      => max(0, (int) ($event['users_id'] ?? 0)),
            'event_type'    => substr((string) ($event['event_type'] ?? 'info'), 0, 64),
            'summary'       => $summary,
            'details'       => (string) ($event['details'] ?? ''),
            'date_creation' => (string) ($event['date_creation'] ?? date('Y-m-d H:i:s')),
        ]);

        return (bool) $DB->insert(self::TABLE_EVENTS, $row);
    }

    /**
     * Lista eventos recentes para uma entidade específica (ou todos quando $entities_id < 0).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getRecentEvents(int $entities_id = -1, int $limit = 30): array
    {
        global $DB;

        if (!isset($DB) || !method_exists($DB, 'request')) {
            return [];
        }
        if (method_exists($DB, 'tableExists') && !$DB->tableExists(self::TABLE_EVENTS)) {
            return [];
        }

        $where = [];
        if ($entities_id >= 0) {
            $where['entities_id'] = $entities_id;
        }

        $it = $DB->request([
            'FROM'  => self::TABLE_EVENTS,
            'WHERE' => $where,
            'ORDER' => ['id DESC'],
            'LIMIT' => max(1, min(200, $limit)),
        ]);

        $events = [];
        foreach ($it as $row) {
            $events[] = $row;
        }
        return $events;
    }

    /**
     * Lista eventos vinculados a um Problema específico.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getEventsForProblem(int $problems_id, int $limit = 50): array
    {
        global $DB;

        if ($problems_id <= 0 || !isset($DB) || !method_exists($DB, 'request')) {
            return [];
        }
        if (method_exists($DB, 'tableExists') && !$DB->tableExists(self::TABLE_EVENTS)) {
            return [];
        }

        $it = $DB->request([
            'FROM'  => self::TABLE_EVENTS,
            'WHERE' => ['problems_id' => $problems_id],
            'ORDER' => ['id DESC'],
            'LIMIT' => max(1, min(200, $limit)),
        ]);

        $events = [];
        foreach ($it as $row) {
            $events[] = $row;
        }
        return $events;
    }

    /**
     * Normaliza uma linha do banco para tipos escalares previsíveis.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalizeRow(array $row): array
    {
        $threshold = (int) ($row['threshold_count'] ?? self::DEFAULT_THRESHOLD);
        if ($threshold < self::MIN_THRESHOLD) {
            $threshold = self::MIN_THRESHOLD;
        } elseif ($threshold > self::MAX_THRESHOLD) {
            $threshold = self::MAX_THRESHOLD;
        }

        $window = (int) ($row['window_hours'] ?? self::DEFAULT_WINDOW_HOURS);
        if ($window < self::MIN_WINDOW_HOURS) {
            $window = self::MIN_WINDOW_HOURS;
        } elseif ($window > self::MAX_WINDOW_HOURS) {
            $window = self::MAX_WINDOW_HOURS;
        }

        $initialStatus = (int) ($row['problem_initial_status'] ?? self::PROBLEM_STATUS_INCOMING);
        if (!in_array($initialStatus, [self::PROBLEM_STATUS_INCOMING, self::PROBLEM_STATUS_ASSIGNED], true)) {
            $initialStatus = self::PROBLEM_STATUS_INCOMING;
        }

        $minRca = (int) ($row['min_rca_length'] ?? 0);
        if ($minRca < 0) {
            $minRca = 0;
        } elseif ($minRca > self::MAX_RCA_LENGTH) {
            $minRca = self::MAX_RCA_LENGTH;
        }

        return [
            'id'                        => (int) ($row['id'] ?? 0),
            'entities_id'               => (int) ($row['entities_id'] ?? 0),
            'is_recursive'              => !empty($row['is_recursive']) ? 1 : 0,
            'is_active'                 => !empty($row['is_active']) ? 1 : 0,
            'auto_escalate'             => !empty($row['auto_escalate']) ? 1 : 0,
            'threshold_count'           => $threshold,
            'window_hours'              => $window,
            'match_category'            => !empty($row['match_category']) ? 1 : 0,
            'match_location'            => !empty($row['match_location']) ? 1 : 0,
            'match_item'                => !empty($row['match_item']) ? 1 : 0,
            'problem_initial_status'    => $initialStatus,
            'require_impact'            => !empty($row['require_impact']) ? 1 : 0,
            'require_cause'             => !empty($row['require_cause']) ? 1 : 0,
            'require_symptom'           => !empty($row['require_symptom']) ? 1 : 0,
            'min_rca_length'            => $minRca,
            'sync_solve_linked_tickets' => !empty($row['sync_solve_linked_tickets']) ? 1 : 0,
            'date_mod'                  => $row['date_mod'] ?? null,
            'inherited_from'            => null,
        ];
    }

    /**
     * @param mixed $val
     */
    private static function toBoolInt($val): int
    {
        return !empty($val) ? 1 : 0;
    }
}

if (!class_exists('PluginProblemflowConfig', false)) {
    if (PluginProblemflowCompat::typedRightname()) {
        class PluginProblemflowConfig extends CommonDBTM implements PluginProblemflowConfigConstants
        {
            use PluginProblemflowConfigBody;

            public static string $rightname = 'config';
        }
    } else {
        class PluginProblemflowConfig extends CommonDBTM implements PluginProblemflowConfigConstants
        {
            use PluginProblemflowConfigBody;

            public static $rightname = 'config';
        }
    }
}

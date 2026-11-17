<?php

/**
 * ------------------------------------------------------------------------
 * Problem Flow - Bootstrap de testes unitários standalone (sem Composer)
 *
 * Copyright (C) 2026 by G. Martins (GPLv3+)
 * ------------------------------------------------------------------------
 */

namespace Glpi\Toolbox {
    if (!class_exists(Sanitizer::class)) {
        class Sanitizer
        {
            /**
             * @param mixed $value
             * @return mixed
             */
            public static function sanitize($value)
            {
                if (is_array($value)) {
                    foreach ($value as $k => $v) {
                        $value[$k] = self::sanitize($v);
                    }
                    return $value;
                }
                if (!is_string($value)) {
                    return $value;
                }
                $encoded = str_replace(
                    ['&', '<', '>', "'", '"'],
                    ['&#38;', '&#60;', '&#62;', '&#39;', '&#34;'],
                    $value
                );
                return addslashes($encoded);
            }

            /**
             * @param mixed $value
             * @return mixed
             */
            public static function decodeHtmlSpecialChars($value)
            {
                if (is_array($value)) {
                    foreach ($value as $k => $v) {
                        $value[$k] = self::decodeHtmlSpecialChars($v);
                    }
                    return $value;
                }
                if (!is_string($value)) {
                    return $value;
                }
                return str_replace(
                    ['&#60;', '&#62;', '&#38;', '&#39;', '&#34;', '&lt;', '&gt;', '&amp;', '&#039;', '&quot;'],
                    ['<', '>', '&', "'", '"', '<', '>', '&', "'", '"'],
                    $value
                );
            }

            /**
             * @param mixed $value
             * @return mixed
             */
            public static function unsanitize($value)
            {
                if (is_array($value)) {
                    foreach ($value as $k => $v) {
                        $value[$k] = self::unsanitize($v);
                    }
                    return $value;
                }
                if (!is_string($value)) {
                    return $value;
                }
                return self::decodeHtmlSpecialChars(stripslashes($value));
            }
        }
    }
}

namespace Glpi\Exception\Http {
    if (!class_exists(AccessDeniedHttpException::class)) {
        class AccessDeniedHttpException extends \RuntimeException
        {
        }
    }
}

namespace {
    if (!defined('GLPI_ROOT')) {
        define('GLPI_ROOT', dirname(__DIR__));
    }

    $envVersion = getenv('PROBLEMFLOW_TEST_GLPI_VERSION');
    if (!defined('GLPI_VERSION')) {
        define('GLPI_VERSION', ($envVersion !== false && $envVersion !== '') ? $envVersion : '11.0.0');
    }

    if (!defined('INFO')) {
        define('INFO', 0);
    }
    if (!defined('WARNING')) {
        define('WARNING', 2);
    }
    if (!defined('ERROR')) {
        define('ERROR', 3);
    }
    if (!defined('READ')) {
        define('READ', 1);
    }
    if (!defined('UPDATE')) {
        define('UPDATE', 2);
    }
    if (!defined('CREATE')) {
        define('CREATE', 4);
    }
    if (!defined('PURGE')) {
        define('PURGE', 128);
    }

    $GLOBALS['TEST_CLI_MODE'] = false;
    $GLOBALS['PLUGIN_HOOKS']  = [];
    $GLOBALS['CFG_GLPI']      = [
        'root_doc' => '',
        'url_base' => 'https://glpi.local',
    ];

    if (!function_exists('__')) {
        function __($str, $domain = 'glpi')
        {
            return (string) $str;
        }
    }

    if (!function_exists('isCommandLineInterface')) {
        function isCommandLineInterface()
        {
            return !empty($GLOBALS['TEST_CLI_MODE']);
        }
    }

    $typedRightname = getenv('PROBLEMFLOW_TEST_TYPED_RIGHTNAME') === '1';
    if ($typedRightname) {
        class CommonGLPI
        {
            public static string $rightname = '';

            public static function getType()
            {
                return static::class;
            }

            public static function createTabEntry($text, $nb = 0)
            {
                return $text . ($nb > 0 ? ' (' . (int) $nb . ')' : '');
            }
        }
    } else {
        class CommonGLPI
        {
            public static $rightname = '';

            public static function getType()
            {
                return static::class;
            }

            public static function createTabEntry($text, $nb = 0)
            {
                return $text . ($nb > 0 ? ' (' . (int) $nb . ')' : '');
            }
        }
    }

    class CommonDBTM extends CommonGLPI
    {
        /** @var array<string, mixed> */
        public $fields = [];

        /** @var array<string, mixed>|false */
        public $input = [];

        /** @var array<int, string> */
        public $updates = [];

        /** @var array<string, mixed> */
        public $oldvalues = [];

        /** @var bool */
        public $canViewItemResult = true;

        public function getID()
        {
            return (int) ($this->fields['id'] ?? 0);
        }

        public function getEntityID()
        {
            return (int) ($this->fields['entities_id'] ?? 0);
        }

        public function canViewItem()
        {
            return $this->canViewItemResult;
        }
    }

    class Ticket extends CommonDBTM
    {
        public static function getFormURLWithID($id = 0, $full = true)
        {
            return '/front/ticket.form.php?id=' . (int) $id;
        }

        public static function getStatus($value)
        {
            $map = [
                1 => 'Novo',
                2 => 'Em atendimento (atribuído)',
                3 => 'Em atendimento (planejado)',
                4 => 'Pendente',
                5 => 'Solucionado',
                6 => 'Fechado',
            ];
            return $map[(int) $value] ?? (string) $value;
        }

        public function add(array $input)
        {
            global $DB, $PLUGIN_HOOKS;

            $row = array_merge([
                'name'              => '',
                'content'           => '',
                'entities_id'       => 0,
                'itilcategories_id' => 0,
                'locations_id'      => 0,
                'status'            => 1,
                'type'              => 1,
                'is_deleted'        => 0,
                'date_creation'     => date('Y-m-d H:i:s'),
            ], $input);

            $DB->insert('glpi_tickets', $row);
            $id           = $DB->lastInsertId('glpi_tickets');
            $row['id']    = $id;
            $this->fields = $DB->getRow('glpi_tickets', $id) ?? $row;

            $hook = $PLUGIN_HOOKS['item_add']['problemflow']['Ticket'] ?? null;
            if (is_callable($hook)) {
                $hook($this);
            }
            return $id;
        }

        public function update(array $input)
        {
            global $DB, $PLUGIN_HOOKS;

            $id = (int) ($input['id'] ?? $this->getID());
            if ($id <= 0) {
                return false;
            }
            $existing = $DB->getRow('glpi_tickets', $id);
            if ($existing === null) {
                return false;
            }

            $updates   = [];
            $oldvalues = [];
            $patch     = [];
            foreach ($input as $k => $v) {
                if ($k === 'id') {
                    continue;
                }
                if (!array_key_exists($k, $existing) || (string) $existing[$k] !== (string) $v) {
                    $updates[]     = $k;
                    $oldvalues[$k] = $existing[$k] ?? null;
                }
                $patch[$k] = $v;
            }

            $DB->update('glpi_tickets', $patch, ['id' => $id]);
            $this->fields    = $DB->getRow('glpi_tickets', $id) ?? $existing;
            $this->updates   = $updates;
            $this->oldvalues = $oldvalues;

            $hook = $PLUGIN_HOOKS['item_update']['problemflow']['Ticket'] ?? null;
            if (is_callable($hook)) {
                $hook($this);
            }
            return true;
        }
    }

    class Problem extends CommonDBTM
    {
        public function add(array $input)
        {
            global $DB;

            $row = array_merge([
                'name'               => '',
                'content'            => '',
                'entities_id'        => 0,
                'itilcategories_id'  => 0,
                'status'             => 1,
                'is_deleted'         => 0,
                'impactcontent'      => '',
                'causecontent'       => '',
                'symptomcontent'     => '',
                'users_id_recipient' => 0,
                'date_creation'      => date('Y-m-d H:i:s'),
            ], $input);

            $DB->insert('glpi_problems', $row);
            $id           = $DB->lastInsertId('glpi_problems');
            $this->fields = $DB->getRow('glpi_problems', $id) ?? array_merge($row, ['id' => $id]);
            return $id;
        }

        public function update(array $input)
        {
            global $DB, $PLUGIN_HOOKS;

            $id = (int) ($input['id'] ?? $this->getID());
            if ($id <= 0) {
                return false;
            }
            $existing = $DB->getRow('glpi_problems', $id);
            if ($existing === null) {
                return false;
            }

            $this->fields = $existing;
            $this->input  = $input;

            $preHook = $PLUGIN_HOOKS['pre_item_update']['problemflow']['Problem'] ?? null;
            if (is_callable($preHook)) {
                $preHook($this);
                if ($this->input === false) {
                    return false;
                }
            }

            $updates   = [];
            $oldvalues = [];
            $patch     = [];
            foreach ($this->input as $k => $v) {
                if ($k === 'id') {
                    continue;
                }
                if (!array_key_exists($k, $existing) || (string) $existing[$k] !== (string) $v) {
                    $updates[]     = $k;
                    $oldvalues[$k] = $existing[$k] ?? null;
                }
                $patch[$k] = $v;
            }

            $DB->update('glpi_problems', $patch, ['id' => $id]);
            $this->fields    = $DB->getRow('glpi_problems', $id) ?? $existing;
            $this->updates   = $updates;
            $this->oldvalues = $oldvalues;

            $postHook = $PLUGIN_HOOKS['item_update']['problemflow']['Problem'] ?? null;
            if (is_callable($postHook)) {
                $postHook($this);
            }

            return true;
        }
    }

    class Problem_Ticket extends CommonDBTM
    {
        public function add(array $input)
        {
            global $DB;

            $DB->insert('glpi_problems_tickets', [
                'problems_id' => (int) ($input['problems_id'] ?? 0),
                'tickets_id'  => (int) ($input['tickets_id'] ?? 0),
            ]);
            $id           = $DB->lastInsertId('glpi_problems_tickets');
            $this->fields = $DB->getRow('glpi_problems_tickets', $id) ?? $input;
            return $id;
        }
    }

    class ITILSolution extends CommonDBTM
    {
        /** @var callable|null */
        public static $onAddCallback = null;

        public function add(array $input)
        {
            global $DB;

            $row = [
                'itemtype' => (string) ($input['itemtype'] ?? 'Ticket'),
                'items_id' => (int) ($input['items_id'] ?? 0),
                'content'  => (string) ($input['content'] ?? ''),
                'users_id' => (int) ($input['users_id'] ?? 0),
                'status'   => (int) ($input['status'] ?? 2),
            ];
            $DB->insert('glpi_itilsolutions', $row);
            $id           = $DB->lastInsertId('glpi_itilsolutions');
            $this->fields = $DB->getRow('glpi_itilsolutions', $id) ?? $row;

            if ($row['itemtype'] === 'Ticket' && $row['items_id'] > 0) {
                $DB->update('glpi_tickets', ['status' => 5], ['id' => $row['items_id']]);
            }

            if (is_callable(self::$onAddCallback)) {
                $cb = self::$onAddCallback;
                $cb($this);
            }

            return $id;
        }
    }

    class Dropdown
    {
        public static function getDropdownName($table, $id)
        {
            global $DB;

            $row = $DB->getRow((string) $table, (int) $id);
            if ($row !== null && isset($row['name'])) {
                return (string) $row['name'];
            }
            return ($table === 'glpi_entities' && (int) $id === 0) ? 'Root entity' : '';
        }
    }

    class Entity extends CommonDBTM
    {
    }

    class DBConnection
    {
        public static function getDefaultCharset(): string
        {
            return 'utf8mb4';
        }

        public static function getDefaultCollation(): string
        {
            return 'utf8mb4_unicode_ci';
        }
    }

    /**
     * Simula GLPI 10.0.0–10.0.10 onde DBmysql::doQuery() não existia (apenas query()).
     */
    class LegacyQueryOnlyDB
    {
        /** @var array<string, array<int, array<string, mixed>>> */
        public $tables = [];

        /** @var array<int, string> */
        public $queries = [];

        public function tableExists(string $table): bool
        {
            return isset($this->tables[$table]);
        }

        public function query(string $sql): bool
        {
            $this->queries[] = $sql;
            if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-zA-Z0-9_]+)`?/i', $sql, $m)) {
                $this->tables[$m[1]] = [];
                return true;
            }
            if (preg_match('/DROP\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-zA-Z0-9_]+)`?/i', $sql, $m)) {
                unset($this->tables[$m[1]]);
                return true;
            }
            return true;
        }
    }

    class DBmysql
    {
        /** @var array<string, array<int, array<string, mixed>>> */
        private $tables = [];

        /** @var array<string, int> */
        private $autoIncrement = [];

        /** @var array<string, int> */
        private $lastIds = [];

        public function __construct()
        {
            $this->reset();
        }

        public function reset(): void
        {
            $this->tables = [
                'glpi_entities'         => [
                    ['id' => 0, 'name' => 'Root entity', 'entities_id' => 0],
                ],
                'glpi_itilcategories'   => [],
                'glpi_tickets'          => [],
                'glpi_items_tickets'    => [],
                'glpi_problems'         => [],
                'glpi_problems_tickets' => [],
                'glpi_itilsolutions'    => [],
            ];
            $this->autoIncrement = [
                'glpi_entities'         => 0,
                'glpi_itilcategories'   => 0,
                'glpi_tickets'          => 0,
                'glpi_items_tickets'    => 0,
                'glpi_problems'         => 0,
                'glpi_problems_tickets' => 0,
                'glpi_itilsolutions'    => 0,
            ];
            $this->lastIds = [];
        }

        public function tableExists(string $table): bool
        {
            return isset($this->tables[$table]);
        }

        public function fieldExists(string $table, string $field): bool
        {
            if ($table === 'glpi_problems') {
                return in_array($field, ['id', 'name', 'content', 'entities_id', 'itilcategories_id', 'status', 'is_deleted', 'impactcontent', 'causecontent', 'symptomcontent'], true);
            }
            return true;
        }

        public function lastInsertId(string $table): int
        {
            return (int) ($this->lastIds[$table] ?? 0);
        }

        public function getRow(string $table, int $id): ?array
        {
            foreach ($this->tables[$table] ?? [] as $row) {
                if ((int) ($row['id'] ?? 0) === $id) {
                    return $row;
                }
            }
            return null;
        }

        public function doQuery(string $sql): bool
        {
            if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-zA-Z0-9_]+)`?/i', $sql, $m)) {
                $table = $m[1];
                if (!isset($this->tables[$table])) {
                    $this->tables[$table]        = [];
                    $this->autoIncrement[$table] = 0;
                }
                return true;
            }
            if (preg_match('/DROP\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-zA-Z0-9_]+)`?/i', $sql, $m)) {
                $table = $m[1];
                unset($this->tables[$table], $this->autoIncrement[$table]);
                return true;
            }
            return true;
        }

        public function escape(string $value): string
        {
            return addslashes($value);
        }

        public function request(array $criteria)
        {
            $from = (string) ($criteria['FROM'] ?? '');
            $rows = $this->tables[$from] ?? [];

            $where   = (array) ($criteria['WHERE'] ?? []);
            $matched = [];
            foreach ($rows as $row) {
                if ($this->matchesWhere($row, $where)) {
                    $matched[] = $row;
                }
            }

            if (!empty($criteria['ORDER'])) {
                $orders = is_array($criteria['ORDER']) ? $criteria['ORDER'] : [$criteria['ORDER']];
                $first  = trim((string) ($orders[0] ?? ''));
                if ($first !== '') {
                    $parts = preg_split('/\s+/', $first);
                    $col   = $parts[0] ?? 'id';
                    $dir   = strtoupper((string) ($parts[1] ?? 'ASC'));
                    usort($matched, static function (array $a, array $b) use ($col, $dir): int {
                        $va = $a[$col] ?? null;
                        $vb = $b[$col] ?? null;
                        if ($va == $vb) {
                            return 0;
                        }
                        $cmp = ($va < $vb) ? -1 : 1;
                        return $dir === 'DESC' ? -$cmp : $cmp;
                    });
                }
            }

            if (isset($criteria['LIMIT'])) {
                $limit   = max(0, (int) $criteria['LIMIT']);
                $matched = array_slice($matched, 0, $limit);
            }

            return new \ArrayIterator($matched);
        }

        public function insert(string $table, array $params): bool
        {
            if (!isset($this->tables[$table])) {
                $this->tables[$table] = [];
            }
            $params = $this->normalizeDbWriteValues($params);
            if (!isset($params['id'])) {
                $next                        = ($this->autoIncrement[$table] ?? 0) + 1;
                $this->autoIncrement[$table] = $next;
                $params['id']                = $next;
            } else {
                $params['id'] = (int) $params['id'];
                if ($params['id'] > ($this->autoIncrement[$table] ?? 0)) {
                    $this->autoIncrement[$table] = $params['id'];
                }
            }
            $this->lastIds[$table]  = (int) $params['id'];
            $this->tables[$table][] = $params;
            return true;
        }

        public function update(string $table, array $params, array $where): bool
        {
            if (!isset($this->tables[$table])) {
                return false;
            }
            $params = $this->normalizeDbWriteValues($params);
            foreach ($this->tables[$table] as $idx => $row) {
                if ($this->matchesWhere($row, $where)) {
                    foreach ($params as $k => $v) {
                        $this->tables[$table][$idx][$k] = $v;
                    }
                }
            }
            return true;
        }

        public function delete(string $table, array $where): bool
        {
            if (!isset($this->tables[$table])) {
                return true;
            }
            $kept = [];
            foreach ($this->tables[$table] as $row) {
                if (!$this->matchesWhere($row, $where)) {
                    $kept[] = $row;
                }
            }
            $this->tables[$table] = $kept;
            return true;
        }

        private function normalizeDbWriteValues(array $params): array
        {
            if (version_compare((string) GLPI_VERSION, '11.0.0-dev', '<')) {
                foreach ($params as $k => $v) {
                    if (is_string($v)) {
                        $params[$k] = stripslashes($v);
                    }
                }
            }
            return $params;
        }

        private function matchesWhere(array $row, array $where): bool
        {
            foreach ($where as $k => $expected) {
                if (!array_key_exists($k, $row)) {
                    return false;
                }
                $actual = $row[$k];
                if (is_array($expected)) {
                    if (!in_array($actual, $expected)) {
                        return false;
                    }
                } elseif ((string) $actual !== (string) $expected) {
                    return false;
                }
            }
            return true;
        }
    }

    class Session
    {
        /** @var int */
        public static $loginUserId = 2;

        /** @var bool */
        public static $cronMode = false;

        /** @var array<int, array{msg: string, check_once: bool, type: int}> */
        public static $messages = [];

        /** @var array<string, int> */
        public static $rights = ['config' => 3, 'problem' => 3];

        /** @var array<int, bool> */
        public static $allowedEntities = [];

        public static function reset(): void
        {
            self::$loginUserId     = 2;
            self::$cronMode        = false;
            self::$messages        = [];
            self::$rights          = ['config' => READ | UPDATE, 'problem' => READ | UPDATE];
            self::$allowedEntities = [];
            $_SESSION              = [];
        }

        public static function isCron(): bool
        {
            return self::$cronMode;
        }

        public static function getLoginUserID($force_human = true)
        {
            return self::$loginUserId > 0 ? self::$loginUserId : false;
        }

        public static function addMessageAfterRedirect($msg, $check_once = false, $message_type = INFO, $reset = false): void
        {
            if ($reset) {
                self::$messages = [];
            }
            self::$messages[] = [
                'msg'        => (string) $msg,
                'check_once' => (bool) $check_once,
                'type'       => (int) $message_type,
            ];
        }

        public static function haveRight($module, $right): bool
        {
            $mask = (int) (self::$rights[$module] ?? 0);
            return ($mask & (int) $right) === (int) $right;
        }

        public static function checkRight($module, $right): void
        {
            if (!self::haveRight($module, $right)) {
                throw new \RuntimeException('Right denied: ' . $module);
            }
        }

        public static function haveAccessToEntity($entities_id, $is_recursive = false): bool
        {
            if (empty(self::$allowedEntities)) {
                return true;
            }
            return !empty(self::$allowedEntities[(int) $entities_id]);
        }
    }

    class Plugin
    {
        /** @var array<int, string> */
        public static $registered = [];

        /** @var bool */
        public static $webDirCalled = false;

        public static function registerClass($class, $params = []): void
        {
            self::$registered[] = (string) $class;
        }

        public static function getWebDir($plugin, $full = false): string
        {
            self::$webDirCalled = true;
            return '/plugins/' . $plugin;
        }
    }

    if (version_compare((string) GLPI_VERSION, '12.0.0-dev', '<')) {
        class Html
        {
            /** @var bool */
            public static $rightErrorDisplayed = false;

            /** @var string|null */
            public static $lastRedirect = null;

            public static function closeForm($display = true): string
            {
                $out = '<input type="hidden" name="_glpi_csrf_token" value="test"></form>';
                if ($display) {
                    echo $out;
                }
                return $out;
            }

            public static function header($title = '', $url = '', $sector = '', $item = ''): void
            {
            }

            public static function footer(): void
            {
            }

            public static function redirect($location): void
            {
                self::$lastRedirect = (string) $location;
            }

            public static function convDateTime($time, $display_sec = false)
            {
                return (string) $time;
            }

            public static function displayRightError(): void
            {
                self::$rightErrorDisplayed = true;
            }
        }
    } else {
        class Html
        {
            /** @var string|null */
            public static $lastRedirect = null;

            public static function closeForm($display = true): string
            {
                $out = '<input type="hidden" name="_glpi_csrf_token" value="test"></form>';
                if ($display) {
                    echo $out;
                }
                return $out;
            }

            public static function header($title = '', $url = '', $sector = '', $item = ''): void
            {
            }

            public static function footer(): void
            {
            }

            public static function redirect($location): void
            {
                self::$lastRedirect = (string) $location;
            }

            public static function convDateTime($time, $display_sec = false)
            {
                return (string) $time;
            }
        }
    }

    class Toolbox
    {
        /**
         * @param mixed $value
         * @return mixed
         */
        public static function addslashes_deep($value)
        {
            if (is_array($value)) {
                foreach ($value as $k => $v) {
                    $value[$k] = self::addslashes_deep($v);
                }
                return $value;
            }
            return is_string($value) ? addslashes($value) : $value;
        }
    }

    $GLOBALS['DB'] = new DBmysql();
    Session::reset();

    require_once GLPI_ROOT . '/problemflow/setup.php';
    require_once GLPI_ROOT . '/problemflow/hook.php';
    plugin_init_problemflow();
}

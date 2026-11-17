<?php

/**
 * ------------------------------------------------------------------------
 * Problem Flow - Suíte de testes unitários standalone
 *
 * Copyright (C) 2026 by G. Martins (GPLv3+)
 * ------------------------------------------------------------------------
 */

require_once __DIR__ . '/bootstrap.php';

function assertSame($expected, $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            sprintf(
                "Falha em [%s]: esperado %s, obtido %s",
                $label,
                var_export($expected, true),
                var_export($actual, true)
            )
        );
    }
}

function assertTrue($condition, string $label): void
{
    if ($condition !== true) {
        throw new RuntimeException(sprintf("Falha em [%s]: esperado true", $label));
    }
}

function resetEnvironment(): void
{
    global $DB;
    $DB->reset();
    Session::reset();
    Plugin::$registered       = [];
    Plugin::$webDirCalled     = false;
    ITILSolution::$onAddCallback = null;
    if (property_exists('Html', 'rightErrorDisplayed')) {
        Html::$rightErrorDisplayed = false;
    }
    Html::$lastRedirect       = null;
    $GLOBALS['TEST_CLI_MODE'] = false;
    $_GET                     = [];
    $_POST                    = [];
    $_SERVER                  = [];
    PluginProblemflowEngine::resetRuntimeState();
    plugin_problemflow_install();
    plugin_init_problemflow();
}

function test_plugin_manifest_and_hooks(): void
{
    global $PLUGIN_HOOKS;
    resetEnvironment();

    $info = plugin_version_problemflow();
    assertSame('1.0.0', $info['version'], 'versao do plugin');
    assertSame('G. Martins', $info['author'], 'autor do plugin');
    assertSame('GPLv3+', $info['license'], 'licenca do plugin');
    assertSame('10.0.0', $info['requirements']['glpi']['min'], 'GLPI min');
    assertSame('12.0.99', $info['requirements']['glpi']['max'], 'GLPI max');
    assertSame('7.4', $info['requirements']['php']['min'], 'PHP min');
    assertTrue(plugin_problemflow_check_prerequisites(), 'check_prerequisites');
    assertTrue(plugin_problemflow_check_config(), 'check_config');

    assertSame(true, $PLUGIN_HOOKS['csrf_compliant']['problemflow'] ?? null, 'csrf_compliant');
    assertSame('front/config.form.php', $PLUGIN_HOOKS['config_page']['problemflow'] ?? null, 'config_page');
    assertTrue(in_array(PluginProblemflowTab::class, Plugin::$registered, true), 'aba registrada em Problem');
    assertSame([PluginProblemflowEngine::class, 'onTicketAdd'], $PLUGIN_HOOKS['item_add']['problemflow']['Ticket'] ?? null, 'hook Ticket item_add');
    assertSame([PluginProblemflowEngine::class, 'onPreProblemUpdate'], $PLUGIN_HOOKS['pre_item_update']['problemflow']['Problem'] ?? null, 'hook Problem pre_item_update');
    assertSame([PluginProblemflowEngine::class, 'onProblemUpdate'], $PLUGIN_HOOKS['item_update']['problemflow']['Problem'] ?? null, 'hook Problem item_update');
}

function test_legacy_query_only_db_on_glpi_10_0_5(): void
{
    global $DB;
    resetEnvironment();

    $origDb = $DB;
    try {
        $legacyDb = new LegacyQueryOnlyDB();
        assertSame(false, method_exists($legacyDb, 'doQuery'), 'LegacyQueryOnlyDB nao possui doQuery');

        $DB = $legacyDb;
        PluginProblemflowConfig::installSchema();

        assertTrue($legacyDb->tableExists(PluginProblemflowConfigConstants::TABLE), 'cria tabela rules via query()');
        assertTrue($legacyDb->tableExists(PluginProblemflowConfigConstants::TABLE_EVENTS), 'cria tabela events via query()');
        assertSame(2, count($legacyDb->queries), '2 comandos CREATE TABLE executados');

        PluginProblemflowConfig::uninstallSchema();
        assertSame(false, $legacyDb->tableExists(PluginProblemflowConfigConstants::TABLE), 'remove tabela rules via query()');
        assertSame(false, $legacyDb->tableExists(PluginProblemflowConfigConstants::TABLE_EVENTS), 'remove tabela events via query()');
        assertSame(4, count($legacyDb->queries), '2 comandos DROP TABLE executados');
    } finally {
        $DB = $origDb;
    }
}

function test_entity_inheritance_nearest_parent_first(): void
{
    global $DB;
    resetEnvironment();

    // Hierarquia: 0 -> 10 -> 20 -> 30 e 0 -> 40 -> 50
    $DB->insert('glpi_entities', ['id' => 10, 'name' => 'Matriz', 'entities_id' => 0]);
    $DB->insert('glpi_entities', ['id' => 20, 'name' => 'Filial A', 'entities_id' => 10]);
    $DB->insert('glpi_entities', ['id' => 30, 'name' => 'Setor A1', 'entities_id' => 20]);
    $DB->insert('glpi_entities', ['id' => 40, 'name' => 'Filial B', 'entities_id' => 0]);
    $DB->insert('glpi_entities', ['id' => 50, 'name' => 'Setor B1', 'entities_id' => 40]);

    PluginProblemflowConfig::saveConfig(0, [
        'is_active'       => 1,
        'is_recursive'    => 1,
        'auto_escalate'   => 1,
        'threshold_count' => 7,
    ]);
    PluginProblemflowConfig::saveConfig(10, [
        'is_active'       => 1,
        'is_recursive'    => 1,
        'auto_escalate'   => 1,
        'threshold_count' => 5,
    ]);
    PluginProblemflowConfig::saveConfig(20, [
        'is_active'       => 1,
        'is_recursive'    => 1,
        'auto_escalate'   => 1,
        'threshold_count' => 2,
    ]);
    PluginProblemflowConfig::saveConfig(40, [
        'is_active'       => 1,
        'is_recursive'    => 0,
        'auto_escalate'   => 1,
        'threshold_count' => 9,
    ]);

    $ancestors30 = PluginProblemflowCompat::getEntitiesAncestorsFallback(30);
    assertSame([20, 10, 0], $ancestors30, 'ancestrais em ordem pai-mais-proximo primeiro');

    $resolved30 = PluginProblemflowConfig::resolveForEntity(30);
    assertSame(2, $resolved30['threshold_count'], 'entidade 30 herda da mais proxima (20)');
    assertSame(20, $resolved30['inherited_from'], 'inherited_from aponta para 20');

    // Entidade 40 tem is_recursive=0, entao 50 pula 40 e herda da raiz 0
    $resolved50 = PluginProblemflowConfig::resolveForEntity(50);
    assertSame(7, $resolved50['threshold_count'], 'entidade 50 ignora pai nao recursivo e herda de 0');
    assertSame(0, $resolved50['inherited_from'], 'inherited_from aponta para 0');

    // Entidade 40 com regra propria nao recursiva ainda usa sua propria regra
    $resolved40 = PluginProblemflowConfig::resolveForEntity(40);
    assertSame(9, $resolved40['threshold_count'], 'entidade 40 usa sua propria regra');
    assertSame(null, $resolved40['inherited_from'], 'regra propria tem inherited_from null');
}

function test_auto_escalation_threshold_reuse_and_deduplication(): void
{
    global $DB;
    resetEnvironment();

    $DB->insert('glpi_itilcategories', ['id' => 5, 'name' => 'Rede Corporativa']);
    PluginProblemflowConfig::saveConfig(0, [
        'is_active'              => 1,
        'is_recursive'           => 1,
        'auto_escalate'          => 1,
        'threshold_count'        => 3,
        'window_hours'           => 24,
        'match_category'         => 1,
        'match_location'         => 1,
        'match_item'             => 1,
        'problem_initial_status' => 2,
    ]);

    // Ticket antigo fora da janela de 24h (nao deve contar)
    $DB->insert('glpi_tickets', [
        'id'                => 90,
        'name'              => 'Queda antiga',
        'entities_id'       => 0,
        'itilcategories_id' => 5,
        'locations_id'      => 12,
        'status'            => 2,
        'is_deleted'        => 0,
        'date_creation'     => date('Y-m-d H:i:s', time() - 48 * 3600),
    ]);
    $DB->insert('glpi_items_tickets', ['tickets_id' => 90, 'itemtype' => 'NetworkEquipment', 'items_id' => 77]);

    $createTicket = static function (string $title, int $locId, int $assetId) use ($DB): Ticket {
        $t  = new Ticket();
        $id = $t->add([
            'name'              => $title,
            'entities_id'       => 0,
            'itilcategories_id' => 5,
            'locations_id'      => $locId,
            'status'            => 1,
            'date_creation'     => date('Y-m-d H:i:s'),
        ]);
        $DB->insert('glpi_items_tickets', [
            'tickets_id' => $id,
            'itemtype'   => 'NetworkEquipment',
            'items_id'   => $assetId,
        ]);
        // Reavalia apos vincular ativo (em fluxo real ou update de ticket)
        PluginProblemflowEngine::onTicketUpdate($t);
        return $t;
    };

    // Ticket 1 e 2 com mesmo local/ativo -> ainda abaixo de 3
    $t1 = $createTicket('Switch sem link #1', 12, 77);
    $t2 = $createTicket('Switch sem link #2', 12, 77);

    // Ticket em outra localizacao/ativo -> nao deve contar para o grupo (12, 77)
    $tOther = $createTicket('Outro local', 99, 88);

    $problemsBefore = iterator_to_array($DB->request(['FROM' => 'glpi_problems']));
    assertSame(0, count($problemsBefore), 'nenhum Problema antes de atingir o limiar');

    // Ticket 3 atinge o limiar 3 -> cria Problema e vincula t1, t2, t3
    $t3 = $createTicket('Switch sem link #3', 12, 77);

    $problemsAfter3 = iterator_to_array($DB->request(['FROM' => 'glpi_problems']));
    assertSame(1, count($problemsAfter3), '1 Problema criado ao atingir limiar 3');
    $problemId = (int) $problemsAfter3[0]['id'];
    assertSame(2, (int) $problemsAfter3[0]['status'], 'status inicial configurado (2 - atribuido)');
    assertTrue(strpos((string) $problemsAfter3[0]['name'], '[ProblemFlow]') === 0, 'titulo possui prefixo [ProblemFlow]');

    $linksAfter3 = iterator_to_array($DB->request(['FROM' => 'glpi_problems_tickets', 'WHERE' => ['problems_id' => $problemId]]));
    assertSame(3, count($linksAfter3), '3 chamados vinculados ao Problema');

    // Ticket 4 equivalente -> reutiliza o Problema aberto existente sem duplicar links
    $t4 = $createTicket('Switch sem link #4', 12, 77);
    $problemsAfter4 = iterator_to_array($DB->request(['FROM' => 'glpi_problems']));
    assertSame(1, count($problemsAfter4), 'reutiliza Problema aberto sem criar duplicado');

    $linksAfter4 = iterator_to_array($DB->request(['FROM' => 'glpi_problems_tickets', 'WHERE' => ['problems_id' => $problemId]]));
    assertSame(4, count($linksAfter4), '4o chamado vinculado e primeiros 3 nao duplicados');

    // Chamado de outro local nao foi vinculado
    $otherLinks = iterator_to_array($DB->request(['FROM' => 'glpi_problems_tickets', 'WHERE' => ['tickets_id' => $tOther->getID()]]));
    assertSame(0, count($otherLinks), 'chamado de local/ativo diferente nao foi vinculado');
}

function test_rca_quality_gate_and_cron_bypass(): void
{
    global $DB;
    resetEnvironment();

    PluginProblemflowConfig::saveConfig(0, [
        'is_active'       => 1,
        'is_recursive'    => 1,
        'require_impact'  => 1,
        'require_cause'   => 1,
        'require_symptom' => 1,
        'min_rca_length'  => 15,
    ]);

    $p   = new Problem();
    $pid = $p->add([
        'name'           => 'Latencia no banco principal',
        'entities_id'    => 0,
        'status'         => 2,
        'impactcontent'  => '<p>Todos os usuarios do ERP afetados.</p>',
        'causecontent'   => '<p>&nbsp;   <br></p>',
        'symptomcontent' => '<p>Timeout em consultas SQL acima de 30s.</p>',
    ]);

    // 1) Bloqueia solucao quando causecontent possui apenas HTML/&nbsp;
    Session::$messages = [];
    $okFail            = $p->update([
        'id'     => $pid,
        'status' => 5,
    ]);
    assertSame(false, $okFail, 'bloqueia transicao para SOLVED com causecontent HTML vazio');
    $rowAfterFail = $DB->getRow('glpi_problems', $pid);
    assertSame(2, (int) $rowAfterFail['status'], 'status permaneceu 2 apos bloqueio');
    assertTrue(!empty(Session::$messages) && Session::$messages[0]['type'] === ERROR, 'mensagem ERROR registrada na sessao');

    $events = PluginProblemflowConfig::getEventsForProblem($pid);
    assertTrue(!empty($events) && $events[0]['event_type'] === 'rca_blocked', 'evento rca_blocked registrado');

    // 2) Permite solucao quando todos os campos RCA atendem ao minimo de caracteres visiveis
    Session::$messages = [];
    $okPass            = $p->update([
        'id'           => $pid,
        'status'       => 5,
        'causecontent' => '<p>Indice fragmentado na tabela glpi_logs causou lock.</p>',
    ]);
    assertSame(true, $okPass, 'permite transicao para SOLVED com RCA valida');
    assertSame(5, (int) $DB->getRow('glpi_problems', $pid)['status'], 'status atualizado para SOLVED (5)');

    // 3) Cron nao e bloqueado mesmo sem RCA preenchida
    $p2Id              = $p->add([
        'name'        => 'Problema encerrado por cron',
        'entities_id' => 0,
        'status'      => 2,
    ]);
    Session::$cronMode = true;
    $okCron            = $p->update([
        'id'     => $p2Id,
        'status' => 6,
    ]);
    Session::$cronMode = false;
    assertSame(true, $okCron, 'Session::isCron() ignora gate RCA');
    assertSame(6, (int) $DB->getRow('glpi_problems', $p2Id)['status'], 'status atualizado para CLOSED (6) no cron');
}

function test_synchronized_closure_and_antireentrancy(): void
{
    global $DB;
    resetEnvironment();

    PluginProblemflowConfig::saveConfig(0, [
        'is_active'                 => 1,
        'is_recursive'              => 1,
        'require_cause'             => 1,
        'min_rca_length'            => 10,
        'sync_solve_linked_tickets' => 1,
    ]);

    $p   = new Problem();
    $pid = $p->add([
        'name'         => 'Falha no servidor DHCP',
        'entities_id'  => 0,
        'status'       => 2,
        'causecontent' => 'Escopo DHCP esgotado por lease de 30 dias.',
    ]);

    $tOpen = new Ticket();
    $tOpenId = $tOpen->add(['name' => 'Sem IP #1', 'entities_id' => 0, 'status' => 2]);

    $tClosed = new Ticket();
    $tClosedId = $tClosed->add(['name' => 'Sem IP antigo', 'entities_id' => 0, 'status' => 6]);

    $link = new Problem_Ticket();
    $link->add(['problems_id' => $pid, 'tickets_id' => $tOpenId]);
    $link->add(['problems_id' => $pid, 'tickets_id' => $tClosedId]);

    // Simula reentrancia durante ITILSolution::add()
    $reentrantCalls = 0;
    ITILSolution::$onAddCallback = static function () use ($p, &$reentrantCalls): void {
        $reentrantCalls++;
        PluginProblemflowEngine::onProblemUpdate($p);
    };

    $ok = $p->update([
        'id'     => $pid,
        'status' => 5,
    ]);
    assertSame(true, $ok, 'Problema solucionado com RCA valida');

    $openRowAfter   = $DB->getRow('glpi_tickets', $tOpenId);
    $closedRowAfter = $DB->getRow('glpi_tickets', $tClosedId);
    assertSame(5, (int) $openRowAfter['status'], 'chamado aberto vinculado foi solucionado');
    assertSame(6, (int) $closedRowAfter['status'], 'chamado ja fechado nao foi alterado');

    $solutions = iterator_to_array($DB->request(['FROM' => 'glpi_itilsolutions', 'WHERE' => ['itemtype' => 'Ticket', 'items_id' => $tOpenId]]));
    assertSame(1, count($solutions), 'exatamente 1 ITILSolution criada (anti-reentrancia impediu loop)');
    assertSame(1, $reentrantCalls, 'callback de reentrancia disparou uma vez e foi bloqueado pelo guarda');
    assertTrue(strpos((string) $solutions[0]['content'], '#' . $pid) !== false, 'solucao referencia o Problema');

    $syncEvents = iterator_to_array($DB->request([
        'FROM'  => PluginProblemflowConfigConstants::TABLE_EVENTS,
        'WHERE' => ['problems_id' => $pid, 'event_type' => 'tickets_synced'],
    ]));
    assertSame(1, count($syncEvents), 'evento tickets_synced gravado uma unica vez');
}

function test_tab_access_control_and_rendering(): void
{
    global $DB;
    resetEnvironment();

    PluginProblemflowConfig::saveConfig(0, [
        'is_active'      => 1,
        'require_impact' => 1,
        'require_cause'  => 1,
        'min_rca_length' => 10,
    ]);

    $p   = new Problem();
    $pid = $p->add([
        'name'          => 'Erro DNS <script>alert(1)</script>',
        'entities_id'   => 0,
        'status'        => 2,
        'impactcontent' => 'Impacto validado em producao.',
        'causecontent'  => '',
    ]);

    $t   = new Ticket();
    $tid = $t->add(['name' => 'Falha DNS <img src=x onerror=alert(1)>', 'entities_id' => 0, 'status' => 2]);
    (new Problem_Ticket())->add(['problems_id' => $pid, 'tickets_id' => $tid]);

    $tab = new PluginProblemflowTab();

    // Usuario sem login -> negado
    Session::$loginUserId = 0;
    assertSame('', $tab->getTabNameForItem($p), 'usuario nao autenticado nao ve aba');

    // Entidade sem permissao -> negado
    Session::$loginUserId     = 2;
    Session::$allowedEntities = [0 => false];
    assertSame('', $tab->getTabNameForItem($p), 'sem acesso a entidade nao ve aba');

    // canViewItem = false -> negado
    Session::$allowedEntities = [0 => true];
    $p->canViewItemResult     = false;
    assertSame('', $tab->getTabNameForItem($p), 'canViewItem false nao ve aba');

    // Acesso permitido -> exibe aba com contador e HTML escapado
    $p->canViewItemResult = true;
    $tabName              = $tab->getTabNameForItem($p);
    assertSame('Problem Flow (1)', $tabName, 'titulo da aba com contador de chamados');

    ob_start();
    $rendered = PluginProblemflowTab::displayTabContentForItem($p);
    $html     = (string) ob_get_clean();
    assertSame(true, $rendered, 'displayTabContentForItem retorna true');
    assertSame(false, strpos($html, '<img src=x onerror=alert(1)>'), 'HTML do titulo de chamado escapado');
    assertTrue(strpos($html, '&lt;img src=x onerror=alert(1)&gt;') !== false, 'titulo escapado com htmlspecialchars');
    assertTrue(strpos($html, 'problemflow-rca-missing') !== false, 'indica campo causecontent pendente');
    assertTrue(strpos($html, 'problemflow-rca-complete') !== false, 'indica campo impactcontent completo');
}

function test_config_form_security_and_xss_escape(): void
{
    resetEnvironment();

    // POST valido
    $_SERVER = [
        'REQUEST_METHOD' => 'POST',
        'HTTP_HOST'      => 'glpi.local',
        'HTTP_ORIGIN'    => 'https://glpi.local',
    ];
    $_POST = [
        'entities_id'               => '0',
        'is_active'                 => '1',
        'is_recursive'              => '1',
        'auto_escalate'             => '1',
        'threshold_count'           => '4',
        'window_hours'              => '12',
        'match_category'            => '1',
        'require_cause'             => '1',
        'min_rca_length'            => '25',
        'sync_solve_linked_tickets' => '1',
    ];

    ob_start();
    include GLPI_ROOT . '/problemflow/front/config.form.php';
    ob_end_clean();

    $cfg = PluginProblemflowConfig::getOwnConfig(0);
    assertSame(1, $cfg['is_active'], 'POST salva is_active');
    assertSame(4, $cfg['threshold_count'], 'POST salva threshold_count');
    assertSame(12, $cfg['window_hours'], 'POST salva window_hours');
    assertSame(25, $cfg['min_rca_length'], 'POST salva min_rca_length');
    assertSame('/plugins/problemflow/front/config.form.php?entities_id=0', Html::$lastRedirect, 'redirect apos POST');

    // GET com entities_id invalido -> fail-closed denyAccess
    $_POST   = [];
    $_GET    = ['entities_id' => 'invalid'];
    $_SERVER = ['REQUEST_METHOD' => 'GET'];
    if (property_exists('Html', 'rightErrorDisplayed')) {
        Html::$rightErrorDisplayed = false;
    }
    include GLPI_ROOT . '/problemflow/front/config.form.php';
    assertTrue(Html::$rightErrorDisplayed === true, 'GET com entities_id invalido aciona denyAccess');

    // GET valido com evento contendo script -> escapado
    PluginProblemflowConfig::logEvent([
        'entities_id' => 0,
        'problems_id' => 7,
        'tickets_id'  => 9,
        'users_id'    => 2,
        'event_type'  => 'xss_probe',
        'summary'     => '<script>alert(1)</script>',
    ]);
    $_GET    = ['entities_id' => '0'];
    $_SERVER = ['REQUEST_METHOD' => 'GET'];
    ob_start();
    include GLPI_ROOT . '/problemflow/front/config.form.php';
    $html = (string) ob_get_clean();
    assertSame(false, strpos($html, '<script>alert(1)</script>'), 'nenhum script cru no config.form.php');
    assertTrue(strpos($html, '&lt;script&gt;alert(1)&lt;/script&gt;') !== false, 'resumo escapado no config.form.php');
}

function test_purge_cleanup(): void
{
    global $DB;
    resetEnvironment();

    PluginProblemflowConfig::logEvent([
        'entities_id' => 0,
        'problems_id' => 11,
        'tickets_id'  => 22,
        'event_type'  => 'ticket_linked',
        'summary'     => 'Vinculo teste',
    ]);
    PluginProblemflowConfig::logEvent([
        'entities_id' => 0,
        'problems_id' => 33,
        'tickets_id'  => 44,
        'event_type'  => 'problem_created',
        'summary'     => 'Outro problema',
    ]);

    $ticket         = new Ticket();
    $ticket->fields = ['id' => 22];
    PluginProblemflowEngine::onTicketPurge($ticket);

    $afterTicketPurge = iterator_to_array($DB->request(['FROM' => PluginProblemflowConfigConstants::TABLE_EVENTS]));
    assertSame(1, count($afterTicketPurge), 'onTicketPurge remove eventos do chamado');

    $problem         = new Problem();
    $problem->fields = ['id' => 33];
    PluginProblemflowEngine::onProblemPurge($problem);

    $afterProblemPurge = iterator_to_array($DB->request(['FROM' => PluginProblemflowConfigConstants::TABLE_EVENTS]));
    assertSame(0, count($afterProblemPurge), 'onProblemPurge remove eventos do problema');
}

function runIsolatedPhpAssertion(array $env, string $code): void
{
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $cmdEnv = array_merge($_ENV, $env);
    foreach ($_SERVER as $k => $v) {
        if (is_string($v) && !isset($cmdEnv[$k])) {
            $cmdEnv[$k] = $v;
        }
    }

    $oneLineCode = trim((string) preg_replace('/\s+/', ' ', $code));
    $process     = proc_open([PHP_BINARY, '-r', $oneLineCode], $descriptorSpec, $pipes, dirname(__DIR__), $cmdEnv);
    if (!is_resource($process)) {
        throw new RuntimeException('Falha ao iniciar subprocesso PHP');
    }

    fclose($pipes[0]);
    $stdout   = stream_get_contents($pipes[1]);
    $stderr   = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0) {
        throw new RuntimeException(
            sprintf("Subprocesso falhou (codigo %d). STDOUT: %s | STDERR: %s", $exitCode, $stdout, $stderr)
        );
    }
}

function test_glpi_10_11_12_subprocesses(): void
{
    // 1) GLPI 10.0.18 (untyped $rightname, public/, preservacao de barras invertidas no RCA)
    runIsolatedPhpAssertion(
        [
            'PROBLEMFLOW_TEST_TYPED_RIGHTNAME' => '0',
            'PROBLEMFLOW_TEST_GLPI_VERSION'    => '10.0.18',
        ],
        '
        require "tests/bootstrap.php";
        if (PluginProblemflowCompat::typedRightname() !== false) { exit(1); }
        $refCfg = new ReflectionProperty("PluginProblemflowConfig", "rightname");
        $refTab = new ReflectionProperty("PluginProblemflowTab", "rightname");
        if ($refCfg->hasType() || $refTab->hasType()) { exit(2); }
        if (PluginProblemflowCompat::glpiMajor() !== 10) { exit(3); }
        if (PluginProblemflowCompat::assetPath("css/problemflow.css") !== "public/css/problemflow.css") { exit(4); }
        $bs = chr(92);
        $stored = "C:" . $bs . "Windows" . $bs . "System32 &#38; &#39;IIS&#39;";
        if (PluginProblemflowCompat::fromCore($stored) !== "C:" . $bs . "Windows" . $bs . "System32 & \x27IIS\x27") { exit(5); }
        $rca = PluginProblemflowEngine::evaluateRcaCompleteness(
            ["causecontent" => $stored],
            ["require_cause" => 1, "require_impact" => 0, "require_symptom" => 0, "min_rca_length" => 25]
        );
        if (!$rca["valid"] || $rca["items"]["cause"]["length"] !== 27) { exit(6); }
        PluginProblemflowCompat::denyAccess();
        if (Html::$rightErrorDisplayed !== true) { exit(7); }
        exit(0);
        '
    );

    // 2) GLPI 11.0.0 (webPath retorna /plugins/problemflow sem chamar Plugin::getWebDir)
    runIsolatedPhpAssertion(
        [
            'PROBLEMFLOW_TEST_TYPED_RIGHTNAME' => '1',
            'PROBLEMFLOW_TEST_GLPI_VERSION'    => '11.0.0',
        ],
        '
        require "tests/bootstrap.php";
        if (PluginProblemflowCompat::typedRightname() !== true) { exit(1); }
        if (PluginProblemflowCompat::glpiMajor() !== 11) { exit(2); }
        if (PluginProblemflowCompat::assetPath("css/problemflow.css") !== "css/problemflow.css") { exit(3); }
        if (PluginProblemflowCompat::webPath(false) !== "/plugins/problemflow") { exit(4); }
        if (PluginProblemflowCompat::webPath(true) !== "https://glpi.local/plugins/problemflow") { exit(5); }
        if (Plugin::$webDirCalled !== false) { exit(6); }
        exit(0);
        '
    );

    // 3) GLPI 12.0.0 (typed $rightname + AccessDeniedHttpException em denyAccess)
    runIsolatedPhpAssertion(
        [
            'PROBLEMFLOW_TEST_TYPED_RIGHTNAME' => '1',
            'PROBLEMFLOW_TEST_GLPI_VERSION'    => '12.0.0',
        ],
        '
        require "tests/bootstrap.php";
        $refCfg = new ReflectionProperty("PluginProblemflowConfig", "rightname");
        $refTab = new ReflectionProperty("PluginProblemflowTab", "rightname");
        if (!$refCfg->hasType() || !$refTab->hasType()) { exit(1); }
        if (PluginProblemflowCompat::glpiMajor() !== 12) { exit(2); }
        $threw = false;
        try {
            PluginProblemflowCompat::denyAccess();
        } catch (\Glpi\Exception\Http\AccessDeniedHttpException $e) {
            $threw = true;
        }
        if (!$threw) { exit(3); }
        exit(0);
        '
    );

    // 4) Uninstall limpa tabelas
    global $DB;
    resetEnvironment();
    assertTrue($DB->tableExists(PluginProblemflowConfigConstants::TABLE), 'rules existe apos install');
    assertTrue($DB->tableExists(PluginProblemflowConfigConstants::TABLE_EVENTS), 'events existe apos install');
    plugin_problemflow_uninstall();
    assertSame(false, $DB->tableExists(PluginProblemflowConfigConstants::TABLE), 'rules removida no uninstall');
    assertSame(false, $DB->tableExists(PluginProblemflowConfigConstants::TABLE_EVENTS), 'events removida no uninstall');
}

$tests = [
    'test_plugin_manifest_and_hooks',
    'test_legacy_query_only_db_on_glpi_10_0_5',
    'test_entity_inheritance_nearest_parent_first',
    'test_auto_escalation_threshold_reuse_and_deduplication',
    'test_rca_quality_gate_and_cron_bypass',
    'test_synchronized_closure_and_antireentrancy',
    'test_tab_access_control_and_rendering',
    'test_config_form_security_and_xss_escape',
    'test_purge_cleanup',
    'test_glpi_10_11_12_subprocesses',
];

$passed = 0;
foreach ($tests as $testFn) {
    $testFn();
    $passed++;
    echo "[OK] {$testFn}\n";
}

echo sprintf("Todos os %d testes passaram com sucesso.\n", $passed);

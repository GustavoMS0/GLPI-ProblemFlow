<?php

/**
 * ------------------------------------------------------------------------
 * Problem Flow - Página de configuração por entidade
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
    include_once __DIR__ . '/../../../inc/includes.php';
}

include_once __DIR__ . '/../inc/compat.class.php';
include_once __DIR__ . '/../inc/config.class.php';

Session::checkRight('config', READ);
$canUpdate = Session::haveRight('config', UPDATE);

$self = PluginProblemflowCompat::webPath() . '/front/config.form.php';
$esc  = static function ($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

$defaultEid = Session::haveAccessToEntity(0) ? 0 : (int) ($_SESSION['glpiactive_entity'] ?? 0);

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) === 'POST') {
    Session::checkRight('config', UPDATE);

    if (!PluginProblemflowCompat::requireSafePost()) {
        PluginProblemflowCompat::denyAccess();
        return;
    }

    $eid = filter_var(
        $_POST['entities_id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 0]]
    );
    if ($eid === false || !Session::haveAccessToEntity((int) $eid)) {
        PluginProblemflowCompat::denyAccess();
        return;
    }
    $eid = (int) $eid;

    PluginProblemflowCompat::normalizeInput();

    if (isset($_POST['delete_config'])) {
        PluginProblemflowConfig::deleteConfig($eid);
        Session::addMessageAfterRedirect(
            __('Configuração específica removida. A entidade passa a herdar da entidade superior ou do padrão.', 'problemflow'),
            true,
            INFO
        );
    } else {
        $saved = PluginProblemflowConfig::saveConfig($eid, $_POST);
        if ($saved) {
            Session::addMessageAfterRedirect(
                __('Regras do Problem Flow salvas com sucesso.', 'problemflow'),
                true,
                INFO
            );
        } else {
            Session::addMessageAfterRedirect(
                __('Não foi possível salvar as regras do Problem Flow.', 'problemflow'),
                true,
                ERROR
            );
        }
    }

    Html::redirect($self . '?entities_id=' . $eid);
    return;
}

if (array_key_exists('entities_id', $_GET)) {
    $parsedGetEid = filter_var(
        $_GET['entities_id'],
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 0]]
    );
    $eid = $parsedGetEid !== false ? (int) $parsedGetEid : -1;
} else {
    $eid = $defaultEid;
}

if ($eid < 0 || !Session::haveAccessToEntity($eid)) {
    PluginProblemflowCompat::denyAccess();
    return;
}

$own        = PluginProblemflowConfig::getOwnConfig($eid);
$effective  = PluginProblemflowConfig::resolveForEntity($eid);
$formValues = $own !== null ? $own : $effective;

$entityName = class_exists('Dropdown')
    ? PluginProblemflowCompat::fromCore((string) Dropdown::getDropdownName('glpi_entities', $eid))
    : ('#' . $eid);

$recentEvents = PluginProblemflowConfig::getRecentEvents($eid, 25);
$disabledAttr = $canUpdate ? '' : ' disabled="disabled"';

Html::header(__('Problem Flow', 'problemflow'), '', 'config', 'plugin');

echo '<div class="problemflow-container">';

// Cabeçalho e seletor de entidade
echo '<div class="problemflow-card problemflow-header-card">';
echo '<div class="problemflow-header-top">';
echo '<div>';
echo '<h2 class="problemflow-title"><i class="ti ti-git-branch" aria-hidden="true"></i> '
    . $esc(__('Problem Flow — Gestão Automática de Problemas', 'problemflow'))
    . '</h2>';
echo '<p class="problemflow-subtitle">'
    . $esc(__('Agrupamento automático de incidentes recorrentes, gates de Causa Raiz (RCA) e sincronização de fechamento.', 'problemflow'))
    . '</p>';
echo '</div>';

if ($own !== null) {
    $badgeClass = (int) $own['is_active'] === 1 ? 'problemflow-badge-ok' : 'problemflow-badge-muted';
    $badgeText  = (int) $own['is_active'] === 1
        ? __('Regra própria ativa', 'problemflow')
        : __('Regra própria inativa', 'problemflow');
} elseif ($effective['inherited_from'] !== null) {
    $parentId   = (int) $effective['inherited_from'];
    $parentName = class_exists('Dropdown')
        ? PluginProblemflowCompat::fromCore((string) Dropdown::getDropdownName('glpi_entities', $parentId))
        : ('#' . $parentId);
    $badgeClass = (int) $effective['is_active'] === 1 ? 'problemflow-badge-inherited' : 'problemflow-badge-muted';
    $badgeText  = sprintf(__('Herdada de: %s', 'problemflow'), $parentName);
} else {
    $badgeClass = 'problemflow-badge-muted';
    $badgeText  = __('Sem regra configurada (padrão inativo)', 'problemflow');
}

echo '<span class="problemflow-badge ' . $esc($badgeClass) . '">' . $esc($badgeText) . '</span>';
echo '</div>';

echo '<form method="get" action="' . $esc($self) . '" class="problemflow-entity-bar">';
echo '<label for="dropdown_entities_id" class="problemflow-entity-label">' . $esc(__('Entidade:', 'problemflow')) . '</label>';
echo '<div class="problemflow-entity-picker">';
if (class_exists('Entity') && method_exists('Entity', 'dropdown')) {
    Entity::dropdown([
        'name'      => 'entities_id',
        'value'     => $eid,
        'on_change' => 'this.form.submit()',
    ]);
} else {
    echo '<input type="number" name="entities_id" value="' . $esc($eid) . '" class="form-control">';
}
echo '</div>';
echo '<button type="submit" class="btn btn-outline-secondary btn-sm">' . $esc(__('Carregar', 'problemflow')) . '</button>';
echo '</form>';
echo '</div>';

$renderToggle = static function (string $name, string $label, string $help, int $checked) use ($esc, $disabledAttr): void {
    $id          = 'pf_' . $name;
    $checkedAttr = $checked === 1 ? ' checked="checked"' : '';
    echo '<label class="problemflow-check-card" for="' . $esc($id) . '">';
    echo '<input type="checkbox" class="form-check-input" id="' . $esc($id) . '" name="' . $esc($name) . '" value="1"' . $checkedAttr . $disabledAttr . '>';
    echo '<span><strong>' . $esc($label) . '</strong><small>' . $esc($help) . '</small></span>';
    echo '</label>';
};

echo '<form method="post" action="' . $esc($self . '?entities_id=' . $eid) . '">';
echo '<input type="hidden" name="entities_id" value="' . $esc($eid) . '">';

// Card 1: Ativação e herança
echo '<div class="problemflow-card">';
echo '<h3 class="problemflow-section-title"><i class="ti ti-adjustments" aria-hidden="true"></i> '
    . $esc(__('Ativação e Herança', 'problemflow')) . ' — ' . $esc($entityName) . '</h3>';
echo '<div class="problemflow-grid">';
$renderToggle(
    'is_active',
    __('Ativar Problem Flow nesta entidade', 'problemflow'),
    __('Quando desmarcado, nenhum escalonamento automático, bloqueio RCA ou sincronização é executado.', 'problemflow'),
    (int) ($formValues['is_active'] ?? 0)
);
$renderToggle(
    'is_recursive',
    __('Aplicar recursivamente às entidades filhas', 'problemflow'),
    __('Entidades subordinadas sem regra própria herdarão esta política.', 'problemflow'),
    (int) ($formValues['is_recursive'] ?? 1)
);
echo '</div>';
echo '</div>';

// Card 2: Agrupamento automático de incidentes
echo '<div class="problemflow-card">';
echo '<h3 class="problemflow-section-title"><i class="ti ti-git-merge" aria-hidden="true"></i> '
    . $esc(__('Agrupamento Automático de Incidentes Recorrentes', 'problemflow')) . '</h3>';
echo '<div class="problemflow-grid">';
$renderToggle(
    'auto_escalate',
    __('Escalonar automaticamente para Problema', 'problemflow'),
    __('Cria ou vincula um Problema aberto ao atingir o limiar de incidentes equivalentes na janela.', 'problemflow'),
    (int) ($formValues['auto_escalate'] ?? 0)
);
$renderToggle(
    'match_category',
    __('Agrupar por categoria ITIL', 'problemflow'),
    __('Exige e compara a mesma categoria (itilcategories_id) entre os chamados.', 'problemflow'),
    (int) ($formValues['match_category'] ?? 1)
);
$renderToggle(
    'match_location',
    __('Agrupar por localização', 'problemflow'),
    __('Exige e compara a mesma localização (locations_id) além dos demais critérios.', 'problemflow'),
    (int) ($formValues['match_location'] ?? 0)
);
$renderToggle(
    'match_item',
    __('Agrupar por ativo vinculado', 'problemflow'),
    __('Compara itens vinculados em glpi_items_tickets entre os incidentes.', 'problemflow'),
    (int) ($formValues['match_item'] ?? 0)
);
echo '</div>';

echo '<div class="problemflow-params-grid">';
echo '<div class="problemflow-field-group">';
echo '<label for="pf_threshold_count" class="form-label fw-semibold">'
    . $esc(__('Limiar de incidentes (2–50)', 'problemflow')) . '</label>';
echo '<input type="number" min="' . PluginProblemflowConfigConstants::MIN_THRESHOLD
    . '" max="' . PluginProblemflowConfigConstants::MAX_THRESHOLD
    . '" id="pf_threshold_count" name="threshold_count" class="form-control problemflow-number-input" value="'
    . $esc((int) ($formValues['threshold_count'] ?? 3)) . '"' . $disabledAttr . '>';
echo '</div>';

echo '<div class="problemflow-field-group">';
echo '<label for="pf_window_hours" class="form-label fw-semibold">'
    . $esc(__('Janela de observação em horas (1–720)', 'problemflow')) . '</label>';
echo '<input type="number" min="' . PluginProblemflowConfigConstants::MIN_WINDOW_HOURS
    . '" max="' . PluginProblemflowConfigConstants::MAX_WINDOW_HOURS
    . '" id="pf_window_hours" name="window_hours" class="form-control problemflow-number-input" value="'
    . $esc((int) ($formValues['window_hours'] ?? 24)) . '"' . $disabledAttr . '>';
echo '</div>';

$initStatus = (int) ($formValues['problem_initial_status'] ?? 1);
echo '<div class="problemflow-field-group">';
echo '<label for="pf_problem_initial_status" class="form-label fw-semibold">'
    . $esc(__('Status inicial do Problema criado', 'problemflow')) . '</label>';
echo '<select id="pf_problem_initial_status" name="problem_initial_status" class="form-select problemflow-select-input"' . $disabledAttr . '>';
echo '<option value="1"' . ($initStatus === 1 ? ' selected="selected"' : '') . '>' . $esc(__('Novo', 'problemflow')) . '</option>';
echo '<option value="2"' . ($initStatus === 2 ? ' selected="selected"' : '') . '>' . $esc(__('Em atendimento (atribuído)', 'problemflow')) . '</option>';
echo '</select>';
echo '</div>';
echo '</div>';
echo '</div>';

// Card 3: Quality Gates RCA e Sincronização
echo '<div class="problemflow-card">';
echo '<h3 class="problemflow-section-title"><i class="ti ti-clipboard-check" aria-hidden="true"></i> '
    . $esc(__('Quality Gates (RCA) e Sincronização de Fechamento', 'problemflow')) . '</h3>';
echo '<div class="problemflow-grid">';
$renderToggle(
    'require_impact',
    __('Exigir Impacto (impactcontent)', 'problemflow'),
    __('Impede solucionar ou fechar Problema sem descrição válida de impacto.', 'problemflow'),
    (int) ($formValues['require_impact'] ?? 0)
);
$renderToggle(
    'require_cause',
    __('Exigir Causa Raiz (causecontent)', 'problemflow'),
    __('Impede solucionar ou fechar Problema sem descrição válida da causa raiz.', 'problemflow'),
    (int) ($formValues['require_cause'] ?? 0)
);
$renderToggle(
    'require_symptom',
    __('Exigir Sintomas (symptomcontent)', 'problemflow'),
    __('Impede solucionar ou fechar Problema sem descrição válida dos sintomas.', 'problemflow'),
    (int) ($formValues['require_symptom'] ?? 0)
);
$renderToggle(
    'sync_solve_linked_tickets',
    __('Sincronizar solução com chamados vinculados', 'problemflow'),
    __('Ao solucionar ou fechar o Problema, aplica solução automática aos chamados abertos vinculados.', 'problemflow'),
    (int) ($formValues['sync_solve_linked_tickets'] ?? 0)
);
echo '</div>';

echo '<div class="problemflow-params-grid">';
echo '<div class="problemflow-field-group">';
echo '<label for="pf_min_rca_length" class="form-label fw-semibold">'
    . $esc(__('Tamanho mínimo por campo RCA (caracteres visíveis, 0–500)', 'problemflow')) . '</label>';
echo '<input type="number" min="0" max="' . PluginProblemflowConfigConstants::MAX_RCA_LENGTH
    . '" id="pf_min_rca_length" name="min_rca_length" class="form-control problemflow-number-input" value="'
    . $esc((int) ($formValues['min_rca_length'] ?? 0)) . '"' . $disabledAttr . '>';
echo '<p class="problemflow-help">'
    . $esc(__('Tags HTML, &nbsp; e espaços repetidos são desconsiderados. Informe 0 para exigir apenas texto não vazio.', 'problemflow'))
    . '</p>';
echo '</div>';
echo '</div>';
echo '</div>';

if ($canUpdate) {
    echo '<div class="problemflow-actions">';
    echo '<button type="submit" name="save_config" value="1" class="btn btn-primary">'
        . '<i class="ti ti-device-floppy" aria-hidden="true"></i> '
        . $esc(__('Salvar regras', 'problemflow'))
        . '</button>';
    if ($own !== null) {
        echo '<button type="submit" name="delete_config" value="1" class="btn btn-outline-danger">'
            . '<i class="ti ti-trash" aria-hidden="true"></i> '
            . $esc(__('Remover regra desta entidade', 'problemflow'))
            . '</button>';
    }
    echo '</div>';
}

Html::closeForm();

// Card 4: Eventos recentes na entidade
echo '<div class="problemflow-card problemflow-events-card">';
echo '<h3 class="problemflow-section-title"><i class="ti ti-history" aria-hidden="true"></i> '
    . $esc(__('Eventos Recentes nesta Entidade', 'problemflow')) . '</h3>';
if (empty($recentEvents)) {
    echo '<p class="text-muted mb-0">' . $esc(__('Nenhum evento registrado até o momento.', 'problemflow')) . '</p>';
} else {
    echo '<div class="table-responsive">';
    echo '<table class="table table-sm table-striped mb-0 problemflow-table">';
    echo '<thead><tr>';
    echo '<th scope="col">' . $esc(__('Data', 'problemflow')) . '</th>';
    echo '<th scope="col">' . $esc(__('Evento', 'problemflow')) . '</th>';
    echo '<th scope="col">' . $esc(__('Problema', 'problemflow')) . '</th>';
    echo '<th scope="col">' . $esc(__('Resumo', 'problemflow')) . '</th>';
    echo '</tr></thead><tbody>';
    foreach ($recentEvents as $ev) {
        $dtRaw  = (string) ($ev['date_creation'] ?? '');
        $dtText = ($dtRaw !== '' && class_exists('Html') && method_exists('Html', 'convDateTime'))
            ? (string) Html::convDateTime($dtRaw)
            : ($dtRaw !== '' ? $dtRaw : '-');
        $pid = (int) ($ev['problems_id'] ?? 0);
        echo '<tr>';
        echo '<td>' . $esc($dtText) . '</td>';
        echo '<td><code>' . $esc((string) ($ev['event_type'] ?? '')) . '</code></td>';
        echo '<td>' . ($pid > 0 ? ('#' . $esc((string) $pid)) : '-') . '</td>';
        echo '<td>' . $esc(PluginProblemflowCompat::fromCore((string) ($ev['summary'] ?? ''))) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}
echo '</div>';

echo '</div>';

Html::footer();

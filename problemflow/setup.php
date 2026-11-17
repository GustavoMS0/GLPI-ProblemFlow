<?php

/**
 * ------------------------------------------------------------------------
 * Problem Flow - Inicialização e metadados do plugin
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

define('PLUGIN_PROBLEMFLOW_VERSION', '1.0.0');
define('PLUGIN_PROBLEMFLOW_MIN_GLPI', '10.0.0');
define('PLUGIN_PROBLEMFLOW_MAX_GLPI', '12.0.99');
define('PLUGIN_PROBLEMFLOW_MIN_PHP', '7.4');

require_once __DIR__ . '/inc/compat.class.php';
require_once __DIR__ . '/inc/config.class.php';
require_once __DIR__ . '/inc/engine.class.php';
require_once __DIR__ . '/inc/tab.class.php';

/**
 * Registra os hooks e classes do plugin no GLPI.
 */
function plugin_init_problemflow(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['problemflow'] = true;
    $PLUGIN_HOOKS['config_page']['problemflow']    = 'front/config.form.php';
    $PLUGIN_HOOKS['add_css']['problemflow']        = [
        PluginProblemflowCompat::assetPath('css/problemflow.css'),
    ];

    Plugin::registerClass(PluginProblemflowTab::class, [
        'addtabon' => ['Problem'],
    ]);

    $PLUGIN_HOOKS['item_add']['problemflow'] = [
        'Ticket' => [PluginProblemflowEngine::class, 'onTicketAdd'],
    ];

    $PLUGIN_HOOKS['pre_item_update']['problemflow'] = [
        'Problem' => [PluginProblemflowEngine::class, 'onPreProblemUpdate'],
    ];

    $PLUGIN_HOOKS['item_update']['problemflow'] = [
        'Ticket'  => [PluginProblemflowEngine::class, 'onTicketUpdate'],
        'Problem' => [PluginProblemflowEngine::class, 'onProblemUpdate'],
    ];

    $PLUGIN_HOOKS['item_purge']['problemflow'] = [
        'Ticket'  => [PluginProblemflowEngine::class, 'onTicketPurge'],
        'Problem' => [PluginProblemflowEngine::class, 'onProblemPurge'],
    ];
}

/**
 * Metadados e requisitos de compatibilidade do plugin.
 *
 * @return array<string, mixed>
 */
function plugin_version_problemflow(): array
{
    return [
        'name'         => __('Problem Flow', 'problemflow'),
        'version'      => PLUGIN_PROBLEMFLOW_VERSION,
        'author'       => 'G. Martins',
        'license'      => 'GPLv3+',
        'homepage'     => 'https://github.com/GustavoMS0/GLPI-ProblemFlow',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_PROBLEMFLOW_MIN_GLPI,
                'max' => PLUGIN_PROBLEMFLOW_MAX_GLPI,
            ],
            'php' => [
                'min' => PLUGIN_PROBLEMFLOW_MIN_PHP,
            ],
        ],
    ];
}

/**
 * Verifica os pré-requisitos de versão do PHP e do GLPI.
 */
function plugin_problemflow_check_prerequisites(): bool
{
    if (version_compare(PHP_VERSION, PLUGIN_PROBLEMFLOW_MIN_PHP, '<')) {
        echo sprintf(
            __('This plugin requires PHP >= %s.', 'problemflow'),
            PLUGIN_PROBLEMFLOW_MIN_PHP
        );
        return false;
    }

    if (defined('GLPI_VERSION')) {
        if (
            version_compare(GLPI_VERSION, PLUGIN_PROBLEMFLOW_MIN_GLPI, '<')
            || version_compare(GLPI_VERSION, PLUGIN_PROBLEMFLOW_MAX_GLPI, '>')
        ) {
            echo sprintf(
                __('This plugin requires GLPI >= %1$s and <= %2$s.', 'problemflow'),
                PLUGIN_PROBLEMFLOW_MIN_GLPI,
                PLUGIN_PROBLEMFLOW_MAX_GLPI
            );
            return false;
        }
    }

    return true;
}

/**
 * Verifica a configuração do plugin.
 *
 * @param bool $verbose
 */
function plugin_problemflow_check_config($verbose = false): bool
{
    return true;
}

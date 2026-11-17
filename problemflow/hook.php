<?php

/**
 * ------------------------------------------------------------------------
 * Problem Flow - Rotinas de instalação e desinstalação
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

require_once __DIR__ . '/inc/compat.class.php';
require_once __DIR__ . '/inc/config.class.php';

/**
 * Instala ou atualiza o esquema de tabelas do Problem Flow.
 */
function plugin_problemflow_install(): bool
{
    PluginProblemflowConfig::installSchema();
    return true;
}

/**
 * Remove as tabelas do Problem Flow na desinstalação.
 */
function plugin_problemflow_uninstall(): bool
{
    PluginProblemflowConfig::uninstallSchema();
    return true;
}

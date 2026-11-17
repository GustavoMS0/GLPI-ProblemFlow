# Problem Flow para GLPI

> [!WARNING]
> **Status: Em Desenvolvimento (Beta / Pré-homologação)**
> Este plugin teve a arquitetura, a segurança e os fluxos validados por revisão de código, auditoria de segurança e testes unitários automatizados em CI (`PHP 7.4–8.3` com mocks de GLPI 10/11/12), mas **ainda não foi testado em uma instância GLPI ao vivo**. Valide em ambiente de homologação antes de usar em produção e reporte eventuais ajustes via Issues.

Agrupamento automático de incidentes recorrentes em Problemas (`Problem`), validação de Análise de Causa Raiz (RCA) e sincronização de fechamento para **GLPI 10, 11 e 12**.

## Funcionalidades

- **Detecção e Agrupamento Automático de Incidentes Recorrentes:**
  - Monitora criação e atualização de chamados (`Ticket`) por entidade com herança recursiva na árvore de entidades (`is_recursive`).
  - Conta incidentes equivalentes abertos dentro de uma janela configurável (`1` a `720` horas, padrão `24h`) usando categoria ITIL (`itilcategories_id`), localização opcional (`locations_id`) e ativo vinculado opcional (`glpi_items_tickets`).
  - Quando o número de ocorrências atinge o limiar configurado (`2` a `50`, padrão `3`), reutiliza um `Problem` aberto equivalente ou cria um novo `Problem` e vincula os chamados pendentes em `glpi_problems_tickets` sem duplicações e com proteção anti-reentrância.
- **Quality Gates de Análise de Causa Raiz (RCA):**
  - Valida os campos `impactcontent`, `causecontent` e `symptomcontent` antes que um usuário interativo altere o status do `Problem` para **Solucionado (`5`)** ou **Fechado (`6`)**.
  - Desconsidera tags HTML, entidades vazias (`&nbsp;`) e espaços repetidos ao verificar o tamanho mínimo configurável (`0` a `500` caracteres visíveis), preservando barras invertidas armazenadas no GLPI 10.
  - Ignora execuções CLI e tarefas automáticas (`Session::isCron()`) para não travar rotinas agendadas.
- **Sincronização de Solução para Chamados Vinculados:**
  - Quando `sync_solve_linked_tickets` está ativo e o `Problem` transiciona para **Solucionado** ou **Fechado**, aplica automaticamente a solução nos chamados abertos vinculados (`ITILSolution` / status Solucionado) com guarda anti-reentrância estática.
- **Aba Dedicada no Problema (`Problem Flow`):**
  - Exibe checklist visual de completude RCA (`Impacto`, `Causa Raiz`, `Sintomas`), resumo da regra efetiva na entidade, lista de incidentes recorrentes vinculados e trilha de eventos do plugin.

## Matriz de Compatibilidade

| Versão do GLPI | Versão do PHP | Status |
| :--- | :--- | :--- |
| **GLPI 10.0.x** (`10.0.0` – `10.0.99`) | PHP `7.4` ou superior | Suportado |
| **GLPI 11.0.x** (`11.0.0` – `11.0.99`) | PHP `8.2` ou superior | Suportado |
| **GLPI 12.0.x** (`12.0.0` – `12.0.99`) | PHP `8.3` ou superior | Suportado |

## Instalação

1. Extraia o diretório `problemflow` dentro da pasta `plugins/` (ou `marketplace/` no GLPI 10) da sua instalação do GLPI.
2. Acesse **Configurar > Plug-ins** no GLPI.
3. Clique em **Instalar** e em seguida em **Ativar** no plugin **Problem Flow**.
4. Acesse **Configurar > Plug-ins > Problem Flow** para configurar limiares, critérios de agrupamento e regras RCA por entidade.

## Licença

Distribuído sob a licença **GNU General Public License v3.0 or later (`GPL-3.0-or-later` / `GPLv3+`)**.  
Copyright (C) 2026 by G. Martins.

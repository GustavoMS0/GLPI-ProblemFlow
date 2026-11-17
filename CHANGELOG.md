# Changelog

Todas as alterações relevantes deste projeto são documentadas neste arquivo.

## [1.0.0] - 2026-11-17

### Adicionado
- Escalonamento e agrupamento automático de incidentes recorrentes em `Problem` (`glpi_problems_tickets`) por entidade com herança recursiva (`is_recursive`), categoria ITIL, localização opcional e ativo vinculado opcional.
- Reutilização de `Problem` aberto equivalente e vinculação deduplicada dos chamados correspondentes com guarda anti-reentrância.
- Quality gates de Análise de Causa Raiz (RCA) em `Problem` (`impactcontent`, `causecontent`, `symptomcontent`) com contagem de caracteres visíveis e isenção para CLI/Cron.
- Sincronização opcional de solução/fechamento do `Problem` para chamados abertos vinculados (`ITILSolution`).
- Aba dedicada `Problem Flow` em `Problem` e painel administrativo por entidade (`front/config.form.php`) com registro de eventos (`glpi_plugin_problemflow_events`).
- Compatibilidade unificada para GLPI 10.0.0 a 12.0.99 e PHP 7.4 a 8.3+.

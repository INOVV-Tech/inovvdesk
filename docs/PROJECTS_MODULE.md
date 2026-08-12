# Módulo Projects — Plano de Desenvolvimento (guia vivo)

Plano completo do Trello de projetos de desenvolvimento para o Inovv Desk
(fork do FoxDesk). Este documento é a referência permanente: **toda** construção
ou adição ao módulo Projects deve seguir esta estrutura, fases e regras.

## 1. Conceito de produto

Domínio novo e independente de tickets: **Projects** → **Boards** (quadros) →
**Lists** (colunas) → **Cards** (tarefas).

- Classificação `shared` (paridade SaaS/self-hosted), como Work, Tickets,
  Clients e Reports.
- Uso: **Agentes e Admins**. Clientes (`role = user`) não acessam (redirect
  para Work) e não recebem o item de navegação.
- Admin = acesso total. Agent = acesso total aos quadros. Permissão por quadro
  (membros convidados) é pós-MVP.
- Código, símbolos e nomes de banco em **inglês** (regra de
  `product-architecture-refactor.md`). Strings de UI via `t()` com
  `en.php` como fonte e `pt.php` como tradução obrigatória (teste
  `portuguese-language-contract-test.php` exige cobertura 1:1).
- Cards **não são tickets e não têm nenhum vínculo com eles em nenhuma fase**:
  domínio, schema, markup, CSS, JS e badges são 100% independentes. Não há
  "criar ticket a partir do card", nem status de ticket no card, nem
  compartilhamento de classes `kanban-*` ou `ticket-priority-inline--*`.
- Prioridade do card usa as chaves `low|medium|high|urgent` como convenção
  própria do módulo, com classes próprias `project-priority-inline--*`
  (proibido reusar `ticket-priority-inline--*`).

## 2. Estrutura (regras do `MONOLITH_EXIT_INVENTORY.md`)

Rotas finas; lógica de negócio em `includes/modules/projects/*`; renderização
reutilizável em `includes/components/*`; comportamento de browser em
`assets/js/*`.

```
pages/projects.php                    # Rota fina: grid de boards (listar)
pages/project.php                     # Rota fina: board detail (colunas + cards)
includes/modules/projects/project-schema.php      # ensure_project_tables() — criação idempotente
includes/modules/projects/project-permissions.php # project_can_view / project_can_manage / staff-guard
includes/modules/projects/project-boards.php      # consulta/CRUD de boards
includes/modules/projects/project-lists.php       # consulta/CRUD de colunas + sort_order
includes/modules/projects/project-cards.php       # consulta/CRUD/move/reorder de cards + view model
includes/modules/projects/project-activity.php    # (pós-MVP) auditoria
includes/components/project-board-surface.php     # render do board (tokens .fd-* e classes próprias project-*)
includes/components/project-card-composer.php     # composer/modal de card
includes/api/project-handler.php                  # ações AJAX (CSRF + revert on error)
assets/js/project-board.js                        # drag & drop HTML5 + fallback mobile (select)
includes/modules/bootstrap.php                    # registrar includes (teste assegura)
includes/header.php                               # nav "Projetos" (nav-item, ícone)
includes/modules/app/app-shell.php                # navigation + capability manage_projects
includes/icons.php                                # ícone 'trello'
includes/lang/en.php, pt.php                      # traduções
includes/schema.sql                               # tabelas para instalação nova
upgrade.php                                       # criação de tabelas para instalações existentes
tests/project-*.php                               # contract tests
```

## 3. Schema

```sql
project_boards (id, name, description, color, is_archived, created_by→users,
                created_at, updated_at)
project_lists  (id, board_id→project_boards CASCADE, name, sort_order,
                created_at, updated_at, INDEX(board_id, sort_order))
project_cards  (id, board_id→project_boards CASCADE, list_id→project_lists CASCADE,
                title, description, assignee_id→users SET NULL, due_date,
                priority VARCHAR(20) DEFAULT 'medium', sort_order, created_by→users,
                created_at, updated_at,
                INDEX(board_id, list_id, sort_order), INDEX(assignee_id), INDEX(due_date))
```

- Criação idempotente via `ensure_project_tables()` (padrão de
  `ticket_status_group_column_exists`) + bloco no `upgrade.php` (padrão
  `SHOW TABLES LIKE`) + DDL no `includes/schema.sql` (padrão `CREATE TABLE IF
  NOT EXISTS`).

## 4. Fases

### Fase 0 — Fundação (feita)
1. Plano em `docs/PROJECTS_MODULE.md` + registro na inventário de módulos.
2. Schema + `project-schema.php` (ensure) + `schema.sql` + `upgrade.php`.
3. `project-permissions.php` + registro no `modules/bootstrap.php`.
4. Rotas `projects`/`project` no `index.php`, nav no `header.php`, entrada no
   `app-shell.php` (navigation + `manage_projects` + `view_projects`).
5. Traduções `en.php`/`pt.php` + ícone `trello` em `includes/icons.php`.
6. Contract tests de fundação + scripts no `package.json`.

### Fase 1 — MVP
1. Grid de boards: listar, criar, renomear, excluir (com confirmação), arquivar.
2. Board detail: colunas ordenadas, criar/renomear/excluir coluna.
3. Cards: criar (quick add), editar título/descrição, **assignee**, **due
   date**, **prioridade**, excluir.
4. **Drag & drop** entre colunas + reordenação na coluna (padrões próprios do
   `assets/js/project-board.js`: placeholder, ghost, revert-shake, fetch com
   `X-CSRF-Token`).
5. Fallback mobile: `<select>` de mover card (`project-mobile-move`).
6. `includes/api/project-handler.php` no roteador: `project-board-save/delete`,
   `project-list-save/delete/reorder`, `project-card-save/move/delete`.
7. UI seguindo `UI_SYSTEM_CONTRACT.md` (`.fd-card`, `.fd-button`, `.fd-input`,
   `.fd-select`, tokens `--fd-*`; sem radius hardcoded).
8. Testes de contrato do MVP **antes** de cada bloco.
9. E2E Playwright smoke (criar board → card → mover) no fim da fase.

### Fase 2 — Pós-MVP (em ordem de prioridade)
1. Card detail em modal (padrão `ticket-detail-modals.php`): descrição rica,
   comentários internos, checklists, anexos (reuso do `upload` API).
2. Filtros/pesquisa no board: assignee, prioridade, prazo, texto (padrão
   `ticket-list-filters.php`, adaptado com classes `project-*`).
3. Integrações: seção na busca global (`global-search.php`), contagens "meus
   cards" no Work/app-feed, avisos de prazo/atribuição via
   `notification-policy.php`. (Nenhuma integração cria vínculo com tickets.)
4. Permissão por quadro (membros), cards arquivados em coluna própria (modelo
   próprio de arquivamento do módulo, sem espelhar o modelo closed do kanban
   de tickets) e templates de board
   (Backlog → Em andamento → Revisão → Concluído).
5. Auditoria: `project-activity.php` + página de activity log (padrão
   `security_log` / `pages/admin/activity.php`).
6. API de agentes (`agent-list-projects`, etc. no `router.php`) seguindo os
   docs `AGENT_API_*` e contrato `app-shell` para clientes nativos.

## 5. Testes (contrato — sempre antes da lógica)

| Teste | Garante |
|---|---|
| `project-foundation-contract-test.php` | rota fina, bootstrap carrega módulos, permissões (client bloqueado), rota no index.php, nav, app-shell |
| `project-boards-contract-test.php` | consulta/CRUD de boards, archiving, created_by, validação |
| `project-cards-contract-test.php` | view model, move entre listas, reordenação, prioridade/assignee/prazo, validação |
| `project-ui-contract-test.php` | classes `.fd-*`/`project-*` no markup, sem radius hardcoded, `t()` em toda string, e **proibição** de classes `kanban-*`/`ticket-priority-inline--*` no módulo |
| `project-js-contract-test.php` | `project-board.js` usa `X-CSRF-Token`, `appConfig`, vocabulário próprio `project-*` (placeholder/ghost só do módulo) |
| update `tests/ui-system-contract.test.js` | superfície nova não quebra o contrato de tokens |

Verificação: `npm run lint:php`, `sh ./bin/run-php.sh tests/project-*.php`,
`npm run test:portuguese-language`, `npm run test:ui-system`,
`npm run test:core-ux-flow`, `npm run test:workflow-contract`.

## 6. Regras permanentes

1. Teste de contrato **antes** da lógica; rota nunca é dona de regra de negócio.
2. Símbolos em inglês, UI via `t()`; qualquer recurso novo nasce na Fase 0
   (schema → permissão → rota → nav → teste).
3. Todo POST via CSRF; todo write auditável (pós-MVP).
4. UI só com primitivas do `UI_SYSTEM_CONTRACT.md` (`fd-*`) e classes próprias
   `project-*`. **Proibido** reusar classes `kanban-*` (board de tickets) ou
   `ticket-priority-inline--*` (badges de ticket) no módulo Projects.
5. Registrar tudo novo na inventário (`MONOLITH_EXIT_INVENTORY.md`), no
   `package.json` e neste documento.
6. Onde houver decisão de produto (campos, permissões, integrações), registrar
   aqui antes de implementar.

## 7. Decisões de produto

- **Proibição de vínculo card ↔ ticket**: cards não têm relação com tickets em
  nenhuma fase (nem "criar ticket a partir do card", nem exibição de status de
  ticket, nem compartilhamento de CSS/JS). Verificada pelos contract tests.
- Prioridades do card: chaves `low|medium|high|urgent` como convenção própria,
  renderizadas só com classes `project-priority-inline--*`.
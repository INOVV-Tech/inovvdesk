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
includes/components/project-card-detail.php       # card detail modal (Fase 2): descrição rica,
                                                  # comentários, checklists, anexos
includes/api/project-handler.php                  # ações AJAX (CSRF + revert on error)
assets/js/project-board.js                        # drag & drop HTML5 + fallback mobile (select)
assets/js/project-card-detail.js                  # card detail modal (Fase 2)
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
project_card_comments        (id, card_id→project_cards CASCADE, author_id→users SET NULL,
                              body TEXT NOT NULL, created_at, updated_at, INDEX(card_id))
project_card_checklists      (id, card_id→project_cards CASCADE, name, sort_order,
                              created_at, INDEX(card_id))
project_card_checklist_items (id, checklist_id→project_card_checklists CASCADE, name,
                              is_checked TINYINT(1) DEFAULT 0, sort_order,
                              created_at, INDEX(checklist_id))
project_card_attachments     (id, card_id→project_cards CASCADE, filename, original_name,
                              mime_type, file_size, uploaded_by→users SET NULL,
                              created_at, INDEX(card_id))
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

### Fase 1 — MVP (feita)
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
1. Card detail em modal (padrão `ticket-detail-modals.php`): descrição rica —
   **feito (1.1–1.5; ver decisão de produto sobre o item 1.6)**:
   1.1 Comentários internos de card (staff-only por construção do módulo) em
       tabela própria `project_card_comments`; autor edita o próprio
       comentário; autor **ou admin** exclui. Sem toggle público/interno
       (não há clientes no módulo).
   1.2 Checklists em 2 níveis (`project_card_checklists` → itens
       `project_card_checklist_items`, `is_checked`), progresso calculado no
       view model.
   1.3 Anexos em tabela própria `project_card_attachments` (a tabela global
       `attachments` é `ticket_id NOT NULL` e acoplada a tickets — proibido
       para o módulo). Upload via **reuso da API de upload** (`upload_file()`
       + endpoint `upload` com `card_id`); exibição via proxy
       `attachment.php` com ramo de autorização próprio (staff; sem tocar no
       ramo de tickets). Sufixo de arquivo arbitrário é armazenado com nome
       aleatório (mesma política da tabela `attachments`).
   1.4 Descrição rica do card com Quill (CDN 1.3.7 + `quill-image-upload.js`,
       upload de imagens `purpose=editor-image`) armazenada como HTML; o
       preview do card na coluna usa `project_card_preview_description()`
       (strip tags) — o composer rápido continua editando texto puro.
   1.5 Montagem do modal em `includes/components/project-card-detail.php`
       (padrão `modal-overlay`/`modal-panel`, `fd-button`, classes
       `project-*`); dados lidos via GET `project-card-detail`; writes via
       POST + CSRF em `project-handler.php`; interatividade em
       `assets/js/project-card-detail.js`.
   1.6 ~~Título/assignee/prioridade/prazo seguem editáveis apenas no composer
       existente; o modal de detalhe os exibe (recap) sem duplicar edição.~~
       **Decisão de produto (2026-08-12): o modal de detalhe também edita**
       título, assignee e prazo inline (salvos via `project-card-save` no
       `change` do `<select>`/`<input>`); o composer rápido segue como segunda
       superfície. Prioridade permanece só no composer (recap no modal).
2. Filtros/pesquisa no board: assignee, prioridade, prazo, texto (padrão
   `ticket-list-filters.php`, adaptado com classes `project-*`) — **feita**:
   - Filtros renderizados pelo servidor (GET form) em
     `project_render_board_filters()`; estado normalizado por
     `project_board_filter_state_from_request()` e aplicado em
     `project_cards_for_board()` (WHERE parametrizado; sem JS novo).
   - `assignee`: `all` | `unassigned` | id de agente.
   - `priority`: `all` | `low|medium|high|urgent`.
   - `due`: `all` | `overdue` | `today` | `upcoming` | `none`. SQL:
     `overdue = due_date IS NOT NULL AND due_date < NOW()`;
     `today = due_date >= CURDATE() AND due_date < CURDATE()+INTERVAL 1 DAY`;
     `upcoming = due_date IS NOT NULL AND due_date >= NOW()`;
     `none = due_date IS NULL`.
   - `search`: LIKE em título + descrição (wildcards escapados).
3. Integrações: seção na busca global (`global-search.php`), contagens "meus
     cards" no Work/app-feed, avisos de prazo/atribuição via
     `notification-policy.php`. (Nenhuma integração cria vínculo com tickets.)
     — **feita**:
    - Busca global: seções staff-only `projects` (boards; `name LIKE`,
      subtitle = descrição, url do board) e `cards` (cards;
      `title/description LIKE`, subtitle = nome do board, url do board);
      `app-shell` as remove para clientes; `shortcuts.js` (palette) passa a
      iterar as duas seções.
    - Work/app-feed: `project_card_work_summary($user, $limit)` em
      `project-cards.php` → `count` (cards abertos atribuídos a mim em boards
      não arquivados) + `overdue_count` + itens (limit) ordenados por prazo
      (`NULLS LAST`); seção staff-only `data-work-my-cards` no Work com
      contagens + lista compacta + link para Projects; `app-feed.php` ganha
      `app_feed_project_cards()` e a chave `projects` no payload.
    - Notification policy: helpers próprios do módulo em
      `notification-policy.php` (sem tocar em tickets):
      `project_card_event_normalize()`,
      `should_send_project_card_email()` (true para `project.card.assigned`
      exceto auto-atribuição, `project.card.due_soon`, `project.card.overdue`;
      false p/ os demais) e `project_card_email_suppression_reason()`.
      Candidatos de alerta por `project_card_due_alert_candidates()`
      (`due_soon` = `due_date BETWEEN NOW() e NOW()+30min`, `overdue` =
      `due_date < NOW()`; só cards com assignee em boards não arquivados).
4. Permissão por quadro (membros), cards arquivados em coluna própria (modelo
   próprio de arquivamento do módulo, sem espelhar o modelo closed do kanban
   de tickets) e templates de board
   (Backlog → Em andamento → Revisão → Concluído). — **feita**:
   - **Permissão por quadro**: tabela própria `project_board_members`
     (board_id, user_id; PK composta). Admin = acesso e gestão totais
     (bypass de membro); agent = vê/edita apenas quadros onde é membro; o
     criador do quadro vira membro automaticamente. Novos helpers em
     `project-permissions.php`: `project_can_view_board()` /
     `project_can_manage_board()` (admin bypass + membro), 
     `project_board_is_member()`, `project_board_members_for_board()`,
     `project_board_member_candidates()`, `project_board_member_add()` / 
     `project_board_member_remove()` (recusa remover o último membro).
     Grid de boards filtra por membros para agents; quadros pré-existentes
     (sem membros) são migrados no `ensure_project_board_members_table()`
     (backfill de `created_by`) e no bloco do `upgrade.php`. API
     `project-board-member-add/remove` é admin-only; gestão de membros na
     página do quadro. Assignee de card (UI e API) fica restrito a membros do
     quadro: `project_assignee_options_for_board()` no board page +
     `project_assignee_valid_for_board()` na camada de escrita; admins seguem
     válidos sem precisar ser membros.
   - **Cards arquivados (coluna própria)**: `project_cards.is_archived` +
     `archived_at` (migração idempotente
     `ensure_project_cards_archived_column()`, padrão
     `ticket_status_group_column_exists`, com bloco ALTER no `upgrade.php`).
     Consultas de board/list/work/due-alerts excluem arquivados
     (`is_archived = 0`); o board page ganha uma coluna própria "Archived"
     (`project_archived_cards_for_board()`, toggle show/hide, restore por
     card com `project_mobile-move` desativado); ação Archive no modal de
     detalhe. API `project-card-archive` (id, archived) com gate por quadro.
   - **Templates de board**: `project_board_templates()` → `blank` |
     `development` (`Backlog` → `In Progress` → `Review` → `Done`);
     `project-board-save` aceita `template` no modo create e cria as listas
     na ordem; picker de template no modal de board (somente modo create).
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
| `project-detail-contract-test.php` | Fase 2: schema das tabelas `project_card_*`, view models (comentários/checklists/anexos), endpoints, markup do modal, traduções, ausência de vínculo com tickets |
| `project-ui-contract-test.php` | classes `.fd-*`/`project-*` no markup, sem radius hardcoded, `t()` em toda string, e **proibição** de classes `kanban-*`/`ticket-priority-inline--*` no módulo |
| `project-js-contract-test.php` | `project-board.js` usa `X-CSRF-Token`, `appConfig`, vocabulário próprio `project-*` (placeholder/ghost só do módulo) |
| `project-integrations-contract-test.php` | Fase 2 item 3: seções `projects`/`cards` na busca global (modelo + palette + app-shell), `project_card_work_summary` (Work + app-feed), policy de notificação de cards (eventos, supressão, candidatos due_soon/overdue), traduções, ausência de vínculo com tickets |
| `project-governance-contract-test.php` | Fase 2 item 4: schema `project_board_members` + colunas `is_archived`/`archived_at` nos três sinks (ensure/schema.sql/upgrade.php), permissão por quadro (admin bypass, agent membro, criador auto-membro, último membro protegido), assignee restrito a membros (UI + validação), arquivamento/restauração de cards (consultas excluem arquivados, work/alerts também), templates de board (blank/development + listas), rotas/endpoints (member admin-only, card-archive com gate), markup `project-*`/`fd-*`, JS (`X-CSRF-Token`, vocabulário próprio), traduções 1:1 e ausência de vínculo com tickets |
| update `tests/ui-system-contract.test.js` | superfície nova não quebra o contrato de tokens |

Verificação: `npm run lint:php`, `sh ./bin/run-php.sh tests/project-*.php`,
`npm run test:portuguese-language`, `npm run test:ui-system`,
`npm run test:core-ux-flow`, `npm run test:workflow-contract`,
`npm run test:project-modules` (inclui o `project-detail-contract-test`).

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
- **Card detail (Fase 2)**: comentários, checklists e anexos vivem em tabelas
  próprias `project_card_*` (nunca na tabela `attachments` de tickets). Upload
  reusa `upload_file()`/endpoint `upload`; autorização de download no proxy
  `attachment.php` tem ramo staff-only para `project_card_attachments`.
- **Comentários de card**: internos por construção (módulo é staff-only);
  edição só do autor; exclusão por autor ou admin.
- **Descrição rica**: armazenada como HTML (Quill); preview em coluna sempre
  via `project_card_preview_description()` (sem tags).
- **Edição de meta no modal de detalhe**: título, assignee e prazo são
  editáveis no próprio modal de detalhe (salvos por `project-card-save` no
  `change`); prioridade fica no composer. O modal de detalhe também é a
  superfície de criação de card ("Add card" → modo create). No modo create,
  mudar assignee/prazo **não** dispara API (só entra no payload do
  `createCard`) — evita "Project not found." (board_id ausente).
- **Filtros de board**: opções e SQL documentados na Fase 2 item 2.
- **Permissão por quadro (2026-08-12)**: adesão controla acesso. Agent só
  vê/edita quadros onde é membro; admin tem acesso e gestão totais. Criador
  vira membro automaticamente; quadros pré-existentes são migrados com o
  criador como membro (backfill em `ensure_project_board_members_table()` e
  `upgrade.php`). Gestão de membros é admin-only (API + UI). Assignee de
  card restrito a membros (admins seguem válidos); nunca remove o último
  membro.
- **Arquivamento de cards (2026-08-12)**: modelo próprio do módulo —
  `project_cards.is_archived` + `archived_at`; cards arquivados somem das
  colunas, ficam visíveis em coluna própria "Archived" no board (toggle
  show/hide com `project_archived_cards_for_board()`) e saem de work summary
  e due-alert candidates. Sem espelhamento do modelo closed do kanban de
  tickets. Restauração pelo botão na coluna Archived.
- **Templates de board (2026-08-12)**: `blank` (nenhuma lista) e
  `development` (Backlog → In Progress → Review → Done, nessa ordem). O
  template só se aplica no modo create do `project-board-save`; picker
  visível somente no modo create do modal de board.
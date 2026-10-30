# Multivegetal Analytics

Dashboard de clientes, vendas e oportunidades de upselling para a loja
WooCommerce da Multi Vegetal. Roda como página no `wp-admin`
(**Multi Vegetal → Analytics**), protegida por capability.

Segue o Design System da marca (`styles.css` do projeto do site) e os padrões
visuais dos demais widgets — card de raio 16px, badge pill, botão de borda 2px,
Poppins, e tokens com fallback embutido, como em `mv-products-grid/assets/grid.css`.

## Estado atual — V1 completa

| Fase | Entrega | Situação |
|---|---|---|
| **A** | `assets/dashboard.css`, `assets/dashboard.js`, `preview.html` | ✅ pronto |
| **B** | Bootstrap PHP + camada de dados + blocos 1 e 2 | ✅ pronto |
| **C** | Produtos + upselling | ✅ pronto |
| **D** | Export CSV server-side, cache, `.zip` instalável | ✅ pronto |
| **E** (1.3) | Abas Clientes e Pedidos, ficha do cliente, evolução com comparação, mapa de calor | ✅ pronto |

## O que o painel responde

| Aba | Perguntas |
|---|---|
| **Visão geral** | Quanto vendi, para quantos clientes, quantos são novos? Estou acima do período anterior (linha atual × comparada, por hora/dia/semana/mês)? Em que dia e hora os clientes compram? Quantos pedidos ficaram parados, cancelados ou malsucedidos? |
| **Clientes** | Quantas vezes cada cliente já comprou? Quem são os melhores do período? De que estados vem a receita? Quem está em risco ou inativo (para reativação)? |
| **Pedidos** | Quais pedidos exatamente entraram nos totais, com os itens de cada um? Qual a compra nº daquele cliente? Qual meio de pagamento traz ticket maior? Qual cupom traz cliente novo e qual só dá desconto a quem já compraria? |
| **Novos vs. Antigos / Produtos / Upselling** | Inalteradas desde a 1.2. |

Clicar no nome de um cliente em qualquer tabela abre a **ficha do cliente**:
número de compras, total gasto, ticket médio, 1ª e última compra, intervalo médio
entre pedidos, **próxima compra prevista** (com aviso de atraso), gasto por mês,
produtos que mais compra, cupons usados e o histórico completo de pedidos com os
itens exatos — além de links para os pedidos dele no WooCommerce, para o relatório
de cliente do Analytics e para o perfil do usuário.

### Como os dados chegam

- **Snapshot em cache** (6 h, transient): KPIs, gráficos e rankings.
- **Sob demanda via AJAX** (sem cache): explorador de clientes, explorador de
  pedidos e a ficha do cliente. A base inteira não cabe num transient, e a ficha é
  lida um cliente por vez. Os três endpoints exigem nonce **e** a capability do
  painel — as respostas carregam nome e e-mail. Os exploradores só consultam
  quando a aba deles é aberta.
- **Exportação CSV** de oportunidades, clientes e pedidos, gerada no servidor,
  herdando o período da tela **e** os filtros do explorador. Texto que começa com
  `= + - @` recebe um apóstrofo para o Excel não executá-lo como fórmula.

Abra `preview.html` direto no navegador: ele roda sem WordPress, com dados
fictícios, e serve para validar o visual sem tocar na loja. Os exploradores e a
ficha do cliente também funcionam no preview: o `MV_AN_MOCK` responde no lugar do
AJAX com uma amostra de 180 clientes gerada com semente fixa.

## Conferindo contra o WooCommerce

Os três primeiros indicadores usam de propósito os mesmos nomes e fórmulas dos
cards de **Analytics → Receita**, para serem conferidos um a um:

| Indicador | Fórmula em `wc_order_stats` | Card equivalente |
|---|---|---|
| Vendas totais | `SUM(total_sales)` | Vendas totais |
| Vendas líquidas | `SUM(net_total)` | Vendas líquidas |
| Pedidos | linhas com `parent_id = 0` | Pedidos |
| Ticket médio | líquidas ÷ pedidos | Valor médio do pedido |

**Compare sempre o mesmo período.** É a causa mais comum de "os números não
batem". O painel abre em "Últimos 30 dias" e os relatórios nativos em "Mês até
hoje" — escolha **Mês até hoje** no seletor para reproduzir a tela inicial do
WooCommerce, ou use o intervalo personalizado com as mesmas datas.

Os períodos são calculados em **dias inteiros** (de 00:00:00 a 23:59:59), como
no WooCommerce. Uma janela que terminasse no instante atual cortaria o primeiro
e o último dia pela metade e já bastaria para os totais divergirem.

Quatro detalhes que também afetam a comparação:

- **Tipo de data.** O Analytics recorta o período pela opção "Tipo de data"
  (Analytics → Configurações), que por padrão é a **data de pagamento** — não a
  de criação. Até a 1.2 este painel usava sempre a criação, e um pedido criado
  num dia e pago no outro caía em períodos diferentes nos dois painéis. Desde a
  1.3 o painel lê a mesma opção (`woocommerce_date_type`) e mostra qual está em
  uso na faixa de contexto ("Datas por"). Exceção proposital: o quadro de
  **status** usa sempre a criação, porque pedido cancelado nunca tem data de
  pagamento e sumiria do quadro feito para mostrá-lo.

- **Status.** O conjunto sai da mesma opção que o Analytics usa
  (`woocommerce_excluded_report_order_statuses`) — por padrão tudo menos
  pendente, malsucedido e cancelado. Aguardando e reembolsado entram.
- **Reembolso não é pedido.** O estorno vira uma linha própria na
  `wc_order_stats`, filha do pedido original e com valores negativos. Os
  valores somam (é assim que o estorno abate a receita), mas a linha não conta
  como pedido.
- **Vendas totais incluem frete e impostos**; líquidas, não. A diferença entre
  os dois cards é exatamente isso.

### Compatibilidade com o armazenamento de pedidos (HPOS)

Os agregados saem das tabelas de lookup do Analytics, que existem com ou sem
HPOS. Os três pontos que precisam de dado fora delas tratam os dois modos:

- **Forma de pagamento**: coluna `payment_method_title` de `wc_orders` com HPOS;
  meta `_payment_method_title` sem.
- **Detalhe da linha no explorador de pedidos** (número do pedido, pagamento,
  cupons, link de edição): `wc_get_order()`, só para os pedidos da página visível.
- **Links**: `admin.php?page=wc-orders` com HPOS, `edit.php?post_type=shop_order` sem.

### Limitação conhecida

Se as tabelas de relatório do WooCommerce estiverem vazias, o painel **não cai
para um segundo motor de leitura** — ele mostra um aviso apontando o caminho da
correção (WooCommerce → Status → Ferramentas). O plano original previa um
fallback via `wc_get_orders()`; construir um segundo mecanismo de agregação
significaria manter duas verdades sobre os mesmos números, e o cenário real
("tabelas existem mas não foram importadas") tem conserto de um clique no
próprio WooCommerce.

## Como gerar o .zip

```powershell
powershell -ExecutionPolicy Bypass -File ..\build-zip.ps1
```

**Não use `Compress-Archive`.** No Windows PowerShell 5.1 ele grava os nomes das
entradas com barra invertida (`mv-analytics\includes\data.php`). A especificação
ZIP exige barra normal, e o extrator do PHP no Linux não trata `\` como
separador de pasta: ele cria arquivos com esse nome literal, todos soltos na
raiz — e o plugin ativa reclamando de arquivo faltando. O `build-zip.ps1` monta
as entradas à mão e falha em voz alta se alguma sair errada.

## Paleta de dados — como foi decidida

As cores das séries **não foram escolhidas no olho**. Elas foram medidas com o
validador de paleta (distância ΔE em OKLab ×100, sob simulação de protanopia e
deuteranopia Machado-Oliveira-Fernandes a severidade 1.0).

O ponto de partida óbvio — usar os tokens da marca direto — reprova nos gates
estruturais: `--color-primary` tem chroma 0.028 e `--color-accent`, 0.065, ambos
abaixo do piso de 0.10 que separa "cor" de "cinza". Só que o motivo do piso é
garantir que as séries se distingam, e a medição direta do par diz o contrário:

| Par | ΔE CVD | ΔE visão normal | Alvo |
|---|---|---|---|
| `#4A5E56` + `#c0a074` (marca) | **24.1** | **27.2** | ≥ 8 / ≥ 15 |
| `#017559` + `#a77a37` (derivado que "passa") | 8.5 | 18.4 | ≥ 8 / ≥ 15 |

O par da marca separa **três vezes melhor** que o par tecnicamente aprovado — e
o derivado traz um verde esmeralda que não existe no site. Como fidelidade
visual é requisito do projeto e a medição desmente o proxy, ficou o par da marca.

**Contrapartida assumida:** `#c0a074` fica em 2.46:1 sobre o branco, abaixo de
3:1. Isso obriga canal de alívio — por isso todo gráfico de duas séries tem
legenda **e** visão de tabela, e a alternância Gráfico/Tabela não é enfeite.

### Papéis

| Papel | Valores | Onde |
|---|---|---|
| Categórico (2 slots) | `#4A5E56` recorrente · `#c0a074` novo | barras empilhadas |
| Sequencial/ordinal | `#8fb9a8 #72a290 #598b78 #487363 #3b5b4f` | coorte, segmentos, ABC |
| Série única | `#4A5E56` | barras nominais (produtos, categorias) |
| Status | `#418261` alta (4.57:1) · `#B85C4A` baixa (4.50:1) | badges de variação |
| Contexto (ênfase) | `#a9a398` período comparado | linha cinza do gráfico de evolução |

O cinza do período comparado foi medido contra a série principal: ΔE **24.2** sob
CVD e **25.7** em visão normal (alvo ≥ 8 / ≥ 15). Ele reprova de propósito no piso
de chroma — é cinza porque é a forma "ênfase": a linha atual é a protagonista e a
comparada é contexto. Fica em 2.44:1 sobre o branco, então o gráfico tem legenda,
tooltip com os dois valores **e** visão de tabela.

A rampa sequencial é a matiz 168.8° da marca, monotônica, com ΔL ≥ 0.06 entre
passos e ponta clara em 2.17:1 — aprovada em todos os gates ordinais.

### Regras que o código segue

- **Barra nominal não é colorida por valor.** Produto e categoria usam um tom só:
  o comprimento já codifica a magnitude, colorir de novo gastaria o canal de identidade.
- **Rampa ordinal só quando a ordem significa algo.** Ciclo de vida do cliente e
  classes ABC sim; nome de produto não.
- **ABC usa a rampa invertida** (`ordinal: 'desc'`): a classe que concentra a
  receita tem de ser a mais escura, não a mais pálida.
- **Status nunca vai só na cor** — cada badge carrega seta (▲/▼) e o número.
  Em custo (descontos, reembolsos) a cor inverte — subir é ruim — mas a seta
  continua dizendo a direção real.
- **Eixo único.** Nenhum gráfico tem dois eixos Y.

## Arquivos

```
mv-analytics/
├── assets/dashboard.css   # Design System aplicado, tudo sob o prefixo .mv-an
├── assets/dashboard.js    # SVG inline, sem biblioteca; hover, ordenação, CSV, AJAX
├── includes/customers.php # ranking, estados, explorador e ficha do cliente
├── includes/orders.php    # explorador de pedidos, cupons, formas de pagamento
├── preview.html           # dados fictícios, roda sem WordPress
└── README.md
```

O CSS é escrito para vencer o `wp-admin` por especificidade (prefixo `.mv-an` em
todo seletor), **sem `!important`** — mesma disciplina de
`mv-product-reviews/assets/reviews.css`.

## Como conferir o visual

```bash
# abre no navegador padrão
start preview.html
```

Vale checar em 1440px, 1024px e 390px. Os breakpoints são os mesmos usados pelos
outros widgets (1024 / 767), mais um corte em 430px para os KPIs.

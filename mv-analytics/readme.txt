=== Multivegetal Analytics ===
Contributors: multivegetal
Tags: woocommerce, analytics, clientes, upsell, relatorios
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.2
Stable tag: 1.2.0
License: GPLv2 or later

Painel de clientes, vendas e oportunidades de upselling para a loja da Multi Vegetal.

== Description ==

Adiciona a página **MV Analytics** no painel do WordPress, com quatro blocos:

1. **Visão geral** — receita, pedidos, ticket médio, clientes únicos, participação
   dos recorrentes e taxa de recompra, cada um comparado com o período anterior.
2. **Novos vs. Antigos** — como cada grupo compra, coorte de recompra por mês da
   primeira compra e a base separada por estágio de relacionamento.
3. **Produtos** — mais vendidos, receita por categoria, curva ABC e a distinção
   entre produto de entrada e produto de recompra.
4. **Upselling** — pares comprados juntos (suporte, confiança e lift), lacunas de
   categoria e uma lista acionável de cliente → produto sugerido, exportável em CSV.

Os dados vêm das tabelas de relatório que o WooCommerce já mantém indexadas, e a
separação entre cliente novo e recorrente usa a mesma marcação dos relatórios
nativos — os totais batem com WooCommerce → Analytics.

== Installation ==

1. Painel → Plugins → Adicionar novo → Enviar plugin.
2. Selecione o arquivo `mv-analytics.zip` e clique em Instalar agora.
3. Ative. A página aparece no menu lateral como **MV Analytics**.

Requer WooCommerce ativo. Se as tabelas de relatório ainda não estiverem
populadas, o próprio painel avisa e indica o caminho em
WooCommerce → Status → Ferramentas.

== Frequently Asked Questions ==

= Quem consegue ver o painel? =

Quem tem a capability `view_woocommerce_reports` (ou `manage_woocommerce`).
Administradores sempre. A lista de upselling mostra nome e e-mail de clientes
reais, então fica atrás da mesma permissão e o CSV é gerado no servidor — não
existe rota pública para esses dados.

= Por que a receita por categoria não fecha com a receita total? =

Produto cadastrado em duas categorias soma nas duas. A coluna responde "quanto
cada categoria movimenta", que é uma pergunta diferente de "como a receita total
se divide".

= Os números demoram para carregar. =

O resultado inteiro é guardado em cache por 6 horas. O primeiro carregamento
depois de uma mudança de período é o mais lento; os seguintes vêm do cache.
O botão "Recalcular agora" limpa e refaz.

== Changelog ==

= 1.2.0 =
* Períodos em dias inteiros. A janela antes terminava no instante atual e
  começava no mesmo horário N dias antes, cortando o primeiro e o último dia
  pela metade — o WooCommerce trabalha em dias fechados, e a diferença
  aparecia nos totais.
* Filtros novos, na mesma divisão do WooCommerce Analytics: presets de
  calendário (hoje, ontem, semana, mês, trimestre, ano e os anteriores de
  cada um), janelas móveis de 30/90/180 dias e 12 meses, e intervalo
  personalizado com data inicial e final.
* Comparação selecionável: período anterior ou ano anterior.
* A exportação CSV passou a herdar o recorte da tela (antes saía sempre do
  período padrão).
* Padrão passou de 12 meses para 30 dias. Para reproduzir exatamente a tela
  inicial do WooCommerce, escolha "Mês até hoje".

= 1.1.0 =
* Números alinhados com o relatório nativo. Três correções que faziam os
  totais divergirem de WooCommerce → Analytics → Receita:
  * Status considerados agora saem da mesma opção que o Analytics usa
    (`woocommerce_excluded_report_order_statuses`), em vez de uma lista fixa
    com concluído e processando. Pedidos aguardando e reembolsados entram,
    como no relatório nativo.
  * Contagem de pedidos ignora as linhas de reembolso (`parent_id = 0`).
    Os valores dessas linhas continuam somando, que é como o estorno abate
    a receita — mas elas não são pedidos.
  * "Vendas totais" e "Vendas líquidas" viraram indicadores separados, com os
    mesmos nomes e fórmulas dos cards do WooCommerce. O ticket médio passou a
    usar a receita líquida, como o "valor médio do pedido" nativo.

= 1.0.0 =
* Versão inicial: quatro blocos, cache em transient, exportação CSV,
  compatibilidade declarada com HPOS e checagem de integridade na ativação.

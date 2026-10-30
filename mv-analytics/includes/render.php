<?php
/**
 * Markup da página do admin.
 *
 * Só o esqueleto sai daqui: os números chegam pelo objeto MV_AN_DATA
 * impresso por wp_add_inline_script, e o assets/dashboard.js preenche os
 * pontos marcados com data-mv. É o mesmo contrato do preview.html, então
 * o preview continua sendo um teste válido do visual.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

function mv_an_category_options() {
	$terms = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => true ] );
	if ( is_wp_error( $terms ) ) return [];

	$out = [];
	foreach ( (array) $terms as $t ) {
		$out[ (int) $t->term_id ] = $t->name . ' (' . (int) $t->count . ')';
	}
	return $out;
}

function mv_an_lookup_notice( $state ) {
	if ( 'ready' === $state ) return;

	$tools = esc_url( admin_url( 'admin.php?page=wc-status&tab=tools' ) );

	if ( 'missing' === $state ) {
		$msg = sprintf(
			/* translators: %s: URL das ferramentas do WooCommerce */
			__( 'As tabelas de relatórios do WooCommerce não existem neste site. Elas são criadas pelo próprio WooCommerce — confira se ele está ativo e atualizado, e depois rode as ferramentas em <a href="%s">WooCommerce → Status → Ferramentas</a>.', 'mv-analytics' ),
			$tools
		);
	} else {
		$msg = sprintf(
			/* translators: %s: URL das ferramentas do WooCommerce */
			__( 'As tabelas de relatórios existem, mas ainda estão vazias — o histórico não foi importado. Rode <strong>“Regenerar tabelas de consulta de produtos”</strong> e a importação do Analytics em <a href="%s">WooCommerce → Status → Ferramentas</a>. Enquanto isso, este painel não tem o que somar.', 'mv-analytics' ),
			$tools
		);
	}
	?>
	<div class="mv-an-notice">
		<span aria-hidden="true">⚠</span>
		<span><?php echo wp_kses_post( $msg ); ?></span>
	</div>
	<?php
}

function mv_an_page_html() {
	$args   = mv_an_current_args();
	$data   = mv_an_get_view_data( $args );
	$state  = isset( $data['state'] ) ? $data['state'] : 'ready';
	$cats   = mv_an_category_options();
	/* O CSV precisa sair no MESMO recorte da tela, então o link carrega
	   todos os filtros — sem isso a exportação viria de outro período. */
	$export = wp_nonce_url(
		add_query_arg(
			[
				'action'     => 'mv_an_export',
				'mv_period'  => $args['period'],
				'mv_cat'     => (int) $args['category'],
				'mv_compare' => $args['compare'],
				'mv_after'   => $args['after'],
				'mv_before'  => $args['before'],
			],
			admin_url( 'admin-post.php' )
		),
		'mv_an_export'
	);
	?>
	<div class="mv-an">
		<div class="mv-an-page">

			<header class="mv-an-head">
				<div class="mv-an-head__brand">
					<span class="mv-an-head__logo">Multivegetal</span>
					<span class="mv-an-head__sub"><?php esc_html_e( 'Analytics', 'mv-analytics' ); ?></span>
				</div>

				<form class="mv-an-head__tools" method="get" action="" data-mv="filters">
					<input type="hidden" name="page" value="<?php echo esc_attr( MV_AN_SLUG ); ?>">

					<?php $periods = mv_an_periods(); ?>
					<label class="mv-an-sr" for="mv-an-period"><?php esc_html_e( 'Período', 'mv-analytics' ); ?></label>
					<select class="mv-an-select" id="mv-an-period" name="mv_period" data-mv="period">
						<?php foreach ( mv_an_period_groups() as $group => $keys ) : ?>
							<optgroup label="<?php echo esc_attr( $group ); ?>">
								<?php foreach ( $keys as $key ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $args['period'], $key ); ?>>
										<?php echo esc_html( $periods[ $key ] ); ?>
									</option>
								<?php endforeach; ?>
							</optgroup>
						<?php endforeach; ?>
					</select>

					<label class="mv-an-sr" for="mv-an-compare"><?php esc_html_e( 'Comparar com', 'mv-analytics' ); ?></label>
					<select class="mv-an-select" id="mv-an-compare" name="mv_compare">
						<?php foreach ( mv_an_compare_modes() as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $args['compare'], $key ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>

					<span class="mv-an-range" data-mv="custom-range" <?php echo 'custom' === $args['period'] ? '' : 'hidden'; ?>>
						<label class="mv-an-sr" for="mv-an-after"><?php esc_html_e( 'Data inicial', 'mv-analytics' ); ?></label>
						<input class="mv-an-select" type="date" id="mv-an-after" name="mv_after"
							value="<?php echo esc_attr( $args['after'] ); ?>"
							max="<?php echo esc_attr( date_i18n( 'Y-m-d' ) ); ?>">
						<span class="mv-an-range__sep" aria-hidden="true">–</span>
						<label class="mv-an-sr" for="mv-an-before"><?php esc_html_e( 'Data final', 'mv-analytics' ); ?></label>
						<input class="mv-an-select" type="date" id="mv-an-before" name="mv_before"
							value="<?php echo esc_attr( $args['before'] ); ?>"
							max="<?php echo esc_attr( date_i18n( 'Y-m-d' ) ); ?>">
						<button type="submit" class="mv-an-btn mv-an-btn--accent"><?php esc_html_e( 'Aplicar', 'mv-analytics' ); ?></button>
					</span>

					<label class="mv-an-sr" for="mv-an-cat"><?php esc_html_e( 'Categoria', 'mv-analytics' ); ?></label>
					<select class="mv-an-select" id="mv-an-cat" name="mv_cat">
						<option value="0"><?php esc_html_e( 'Todas as categorias', 'mv-analytics' ); ?></option>
						<?php foreach ( $cats as $id => $label ) : ?>
							<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $args['category'], $id ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>

					<button type="button" class="mv-an-btn mv-an-btn--onhead" data-mv="refresh">
						<?php esc_html_e( 'Recalcular agora', 'mv-analytics' ); ?>
					</button>
				</form>
			</header>

			<?php mv_an_lookup_notice( $state ); ?>

			<div class="mv-an-meta" data-mv="meta"></div>

			<?php if ( 'ready' === $state ) : ?>

			<div class="mv-an-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Seções do dashboard', 'mv-analytics' ); ?>">
				<button class="mv-an-tab" role="tab" type="button" data-panel="mv-p1" aria-controls="mv-p1"><?php esc_html_e( 'Visão geral', 'mv-analytics' ); ?></button>
				<button class="mv-an-tab" role="tab" type="button" data-panel="mv-p5" aria-controls="mv-p5"><?php esc_html_e( 'Clientes', 'mv-analytics' ); ?></button>
				<button class="mv-an-tab" role="tab" type="button" data-panel="mv-p6" aria-controls="mv-p6"><?php esc_html_e( 'Pedidos', 'mv-analytics' ); ?></button>
				<button class="mv-an-tab" role="tab" type="button" data-panel="mv-p2" aria-controls="mv-p2"><?php esc_html_e( 'Novos vs. Antigos', 'mv-analytics' ); ?></button>
				<button class="mv-an-tab" role="tab" type="button" data-panel="mv-p3" aria-controls="mv-p3"><?php esc_html_e( 'Produtos', 'mv-analytics' ); ?></button>
				<button class="mv-an-tab" role="tab" type="button" data-panel="mv-p4" aria-controls="mv-p4"><?php esc_html_e( 'Upselling', 'mv-analytics' ); ?></button>
			</div>

			<!-- ===== 1. VISÃO GERAL ===== -->
			<section class="mv-an-panel" id="mv-p1" role="tabpanel" tabindex="0">
				<div class="mv-an-kpis" data-mv="kpis"></div>

				<div class="mv-an-grid">
					<div class="mv-an-col-12">
						<div class="mv-an-card">
							<div class="mv-an-card__head" data-mv="trend-toggle">
								<h2 class="mv-an-card__title"><?php esc_html_e( 'Evolução das vendas', 'mv-analytics' ); ?></h2>
								<p class="mv-an-card__hint"><?php esc_html_e( 'Linha cheia é o período selecionado; a cinza, o período de comparação alinhado dia a dia (ou semana a semana). A unidade acompanha o tamanho do período: hora, dia, semana ou mês — como no WooCommerce Analytics.', 'mv-analytics' ); ?></p>
							</div>
							<div class="mv-an-card__body">
								<div class="mv-an-chartbar">
									<div class="mv-an-seg" role="group" aria-label="<?php esc_attr_e( 'Indicador do gráfico', 'mv-analytics' ); ?>" data-mv="trend-metric"></div>
									<div class="mv-an-legend mv-an-legend--inline">
										<span class="mv-an-legend__item"><i class="mv-an-legend__swatch mv-an-legend__swatch--s1"></i><?php esc_html_e( 'Período atual', 'mv-analytics' ); ?></span>
										<span class="mv-an-legend__item"><i class="mv-an-legend__swatch mv-an-legend__swatch--prev"></i><?php esc_html_e( 'Período comparado', 'mv-analytics' ); ?></span>
									</div>
								</div>
								<div class="mv-an-chart" data-mv="trend-chart"></div>
								<div data-mv="trend-table"></div>
							</div>
						</div>
					</div>

					<div class="mv-an-col-12">
						<div class="mv-an-card">
							<div class="mv-an-card__head" data-mv="monthly-toggle">
								<h2 class="mv-an-card__title"><?php esc_html_e( 'Receita — novos vs. recorrentes', 'mv-analytics' ); ?></h2>
								<p class="mv-an-card__hint"><?php esc_html_e( 'Um pedido conta como “novo” quando é a primeira compra daquele cliente — a mesma marcação que o WooCommerce usa nos relatórios nativos. O gráfico soma vendas totais (bruto, com frete e impostos), que é o card “Vendas totais” de Analytics → Receita.', 'mv-analytics' ); ?></p>
							</div>
							<div class="mv-an-card__body">
								<div class="mv-an-legend">
									<span class="mv-an-legend__item"><i class="mv-an-legend__swatch mv-an-legend__swatch--s2"></i><?php esc_html_e( 'Novos', 'mv-analytics' ); ?></span>
									<span class="mv-an-legend__item"><i class="mv-an-legend__swatch mv-an-legend__swatch--s1"></i><?php esc_html_e( 'Recorrentes', 'mv-analytics' ); ?></span>
								</div>
								<div class="mv-an-chart" data-mv="monthly-chart"></div>
								<div data-mv="monthly-table"></div>
							</div>
						</div>
					</div>

					<div class="mv-an-col-7">
						<div class="mv-an-card">
							<div class="mv-an-card__head">
								<h2 class="mv-an-card__title"><?php esc_html_e( 'Quando os clientes compram', 'mv-analytics' ); ?></h2>
								<p class="mv-an-card__hint"><?php esc_html_e( 'Pedidos por dia da semana e hora. Os horários mais escuros são a melhor janela para disparar e-mail, push ou campanha — e o pior momento para manutenção no site.', 'mv-analytics' ); ?></p>
							</div>
							<div class="mv-an-card__body"><div data-mv="heatmap"></div></div>
						</div>
					</div>

					<div class="mv-an-col-5">
						<div class="mv-an-card">
							<div class="mv-an-card__head">
								<h2 class="mv-an-card__title"><?php esc_html_e( 'Status dos pedidos', 'mv-analytics' ); ?></h2>
								<p class="mv-an-card__hint"><?php esc_html_e( 'Todos os pedidos criados no período, inclusive os que o Analytics não soma (pendente, cancelado, malsucedido). Muito pedido parado ou cancelado costuma ser problema de pagamento ou de estoque.', 'mv-analytics' ); ?></p>
							</div>
							<div class="mv-an-card__body"><div data-mv="order-status"></div></div>
						</div>
					</div>
				</div>
			</section>

			<!-- ===== 5. CLIENTES ===== -->
			<section class="mv-an-panel" id="mv-p5" role="tabpanel" tabindex="0" hidden>
				<div class="mv-an-grid">
					<div class="mv-an-col-7">
						<div class="mv-an-card">
							<div class="mv-an-card__head" data-mv="freq-toggle">
								<h2 class="mv-an-card__title"><?php esc_html_e( 'Quantas vezes cada cliente já comprou', 'mv-analytics' ); ?></h2>
								<p class="mv-an-card__hint"><?php esc_html_e( 'Clientes que compraram no período, separados pelo total de pedidos que já fizeram na loja. Quanto mais a massa se desloca para a direita, mais a receita depende de quem já é de casa.', 'mv-analytics' ); ?></p>
							</div>
							<div class="mv-an-card__body">
								<div class="mv-an-chart" data-mv="freq-chart"></div>
								<div data-mv="freq-table"></div>
							</div>
						</div>
					</div>

					<div class="mv-an-col-5">
						<div class="mv-an-card">
							<div class="mv-an-card__head">
								<h2 class="mv-an-card__title"><?php esc_html_e( 'Receita por estado', 'mv-analytics' ); ?></h2>
								<p class="mv-an-card__hint"><?php esc_html_e( 'Pelo endereço do cadastro do cliente no WooCommerce.', 'mv-analytics' ); ?></p>
							</div>
							<div class="mv-an-card__body"><div data-mv="geo"></div></div>
						</div>
					</div>

					<div class="mv-an-col-12">
						<div class="mv-an-card">
							<div class="mv-an-card__head">
								<h2 class="mv-an-card__title"><?php esc_html_e( 'Melhores clientes do período', 'mv-analytics' ); ?></h2>
								<p class="mv-an-card__hint"><?php esc_html_e( 'Clique no nome para abrir a ficha completa: todas as compras, produtos favoritos, intervalo entre pedidos e a próxima compra prevista.', 'mv-analytics' ); ?></p>
							</div>
							<div class="mv-an-card__body"><div data-mv="top-customers"></div></div>
						</div>
					</div>

					<div class="mv-an-col-12">
						<div class="mv-an-card">
							<div class="mv-an-card__head">
								<h2 class="mv-an-card__title"><?php esc_html_e( 'Base de clientes', 'mv-analytics' ); ?></h2>
								<p class="mv-an-card__hint"><?php esc_html_e( 'Uma linha por cliente com o histórico completo — não só o período da tela. Busque por nome, e-mail, cidade ou ID; filtre por estágio para montar listas de reativação.', 'mv-analytics' ); ?></p>
							</div>
							<div class="mv-an-card__body"><div data-mv="customers-explorer"></div></div>
						</div>
					</div>
				</div>
			</section>

			<!-- ===== 6. PEDIDOS ===== -->
			<section class="mv-an-panel" id="mv-p6" role="tabpanel" tabindex="0" hidden>
				<div class="mv-an-grid">
					<div class="mv-an-col-12">
						<div class="mv-an-card">
							<div class="mv-an-card__head">
								<h2 class="mv-an-card__title"><?php esc_html_e( 'Pedidos do período', 'mv-analytics' ); ?></h2>
								<p class="mv-an-card__hint"><?php esc_html_e( 'Os mesmos pedidos que os totais da tela somaram, com os itens exatos de cada compra. “Compra nº” diz quantas vezes aquele cliente já comprou. Busque por número, cliente, e-mail ou produto; o número abre o pedido no WooCommerce.', 'mv-analytics' ); ?></p>
							</div>
							<div class="mv-an-card__body"><div data-mv="orders-explorer"></div></div>
						</div>
					</div>

					<div class="mv-an-col-6">
						<div class="mv-an-card">
							<div class="mv-an-card__head">
								<h2 class="mv-an-card__title"><?php esc_html_e( 'Formas de pagamento', 'mv-analytics' ); ?></h2>
								<p class="mv-an-card__hint"><?php esc_html_e( 'Receita e ticket médio por meio de pagamento. Ticket muito maior num meio específico costuma indicar parcelamento — vale destacar na página do produto.', 'mv-analytics' ); ?></p>
							</div>
							<div class="mv-an-card__body"><div data-mv="payments"></div></div>
						</div>
					</div>

					<div class="mv-an-col-6">
						<div class="mv-an-card">
							<div class="mv-an-card__head">
								<h2 class="mv-an-card__title"><?php esc_html_e( 'Cupons', 'mv-analytics' ); ?></h2>
								<p class="mv-an-card__hint"><?php esc_html_e( '“1ª compra” é a fatia dos pedidos com o cupom que vieram de cliente novo. Cupom que só pega recorrente não está trazendo ninguém — está dando desconto a quem já compraria.', 'mv-analytics' ); ?></p>
							</div>
							<div class="mv-an-card__body"><div data-mv="coupons"></div></div>
						</div>
					</div>
				</div>
			</section>

			<!-- ===== 2. NOVOS VS. ANTIGOS ===== -->
			<section class="mv-an-panel" id="mv-p2" role="tabpanel" tabindex="0" hidden>
				<div class="mv-an-grid">
					<div class="mv-an-col-7">
						<div class="mv-an-card">
							<div class="mv-an-card__head">
								<h2 class="mv-an-card__title"><?php esc_html_e( 'Como cada grupo compra', 'mv-analytics' ); ?></h2>
								<p class="mv-an-card__hint"><?php esc_html_e( 'A diferença de comportamento é o que justifica tratar os dois grupos com ofertas distintas.', 'mv-analytics' ); ?></p>
							</div>
							<div class="mv-an-card__body"><div data-mv="compare"></div></div>
						</div>
					</div>

					<div class="mv-an-col-5">
						<div class="mv-an-card">
							<div class="mv-an-card__head">
								<h2 class="mv-an-card__title"><?php esc_html_e( 'Base por estágio de relacionamento', 'mv-analytics' ); ?></h2>
								<p class="mv-an-card__hint"><?php esc_html_e( 'Recência decide primeiro, frequência depois — um cliente fiel que sumiu há um ano conta como inativo, não como fiel. A ordem carrega significado, por isso a escala vai do tom claro ao escuro.', 'mv-analytics' ); ?></p>
							</div>
							<div class="mv-an-card__body"><div data-mv="segments"></div></div>
						</div>
					</div>

					<div class="mv-an-col-12">
						<div class="mv-an-card">
							<div class="mv-an-card__head">
								<h2 class="mv-an-card__title"><?php esc_html_e( 'Coorte de recompra por mês da 1ª compra', 'mv-analytics' ); ?></h2>
								<p class="mv-an-card__hint"><?php esc_html_e( 'Cada linha acompanha quem estreou naquele mês: quantos voltaram em 30, 60 e 90 dias. Só aparecem coortes que já completaram 90 dias — as mais recentes ainda estão maturando e comparar seria enganoso.', 'mv-analytics' ); ?></p>
							</div>
							<div class="mv-an-card__body"><div data-mv="cohort"></div></div>
						</div>
					</div>
				</div>
			</section>

			<!-- ===== 3. PRODUTOS ===== -->
			<section class="mv-an-panel" id="mv-p3" role="tabpanel" tabindex="0" hidden>
				<div class="mv-an-grid">
					<div class="mv-an-col-7">
						<div class="mv-an-card">
							<div class="mv-an-card__head" data-mv="products-toggle">
								<h2 class="mv-an-card__title"><?php esc_html_e( 'Produtos mais vendidos por receita', 'mv-analytics' ); ?></h2>
							</div>
							<div class="mv-an-card__body">
								<div data-mv="products-chart"></div>
								<div data-mv="products-table"></div>
							</div>
						</div>
					</div>

					<div class="mv-an-col-5">
						<div class="mv-an-card" style="margin-bottom:20px">
							<div class="mv-an-card__head">
								<h2 class="mv-an-card__title"><?php esc_html_e( 'Receita por categoria', 'mv-analytics' ); ?></h2>
								<p class="mv-an-card__hint"><?php esc_html_e( 'Produto cadastrado em duas categorias soma nas duas — a coluna mede quanto cada categoria movimenta, e por isso não fecha com a receita total.', 'mv-analytics' ); ?></p>
							</div>
							<div class="mv-an-card__body"><div data-mv="categories"></div></div>
						</div>

						<div class="mv-an-card">
							<div class="mv-an-card__head">
								<h2 class="mv-an-card__title"><?php esc_html_e( 'Curva ABC do catálogo', 'mv-analytics' ); ?></h2>
								<p class="mv-an-card__hint"><?php esc_html_e( 'Classe A concentra os primeiros 80% da receita, B os 15% seguintes e C a cauda longa. Serve para decidir onde vale estoque, foto nova e verba de mídia — e o que só ocupa prateleira.', 'mv-analytics' ); ?></p>
							</div>
							<div class="mv-an-card__body"><div data-mv="abc"></div></div>
						</div>
					</div>

					<div class="mv-an-col-12">
						<div class="mv-an-card">
							<div class="mv-an-card__head">
								<h2 class="mv-an-card__title"><?php esc_html_e( 'Produto de entrada ou de recompra?', 'mv-analytics' ); ?></h2>
								<p class="mv-an-card__hint"><?php esc_html_e( '“1ª compra” é a fatia dos pedidos de estreia que contêm o produto; “recompra”, a fatia dos pedidos de clientes que já compraram antes. Produto de entrada abre a relação; produto de recompra a sustenta — e é dessa distinção que sai a régua de ofertas.', 'mv-analytics' ); ?></p>
							</div>
							<div class="mv-an-card__body"><div data-mv="entry"></div></div>
						</div>
					</div>
				</div>
			</section>

			<!-- ===== 4. UPSELLING ===== -->
			<section class="mv-an-panel" id="mv-p4" role="tabpanel" tabindex="0" hidden>
				<div class="mv-an-grid">
					<div class="mv-an-col-12">
						<div class="mv-an-card">
							<div class="mv-an-card__head">
								<h2 class="mv-an-card__title"><?php esc_html_e( 'Produtos comprados juntos', 'mv-analytics' ); ?></h2>
								<p class="mv-an-card__hint"><?php echo wp_kses_post( __( '<strong>Suporte</strong> = fatia de todos os pedidos que contêm os dois. <strong>Confiança</strong> = de quem levou A, quantos levaram B. <strong>Lift</strong> = quantas vezes esse par acontece acima do acaso — abaixo de 1,00 os produtos se repelem; acima de 2,00 a dupla é forte o bastante para virar kit ou sugestão na página do produto.', 'mv-analytics' ) ); ?></p>
							</div>
							<div class="mv-an-card__body"><div data-mv="basket"></div></div>
						</div>
					</div>

					<div class="mv-an-col-12">
						<div class="mv-an-card">
							<div class="mv-an-card__head">
								<h2 class="mv-an-card__title"><?php esc_html_e( 'Lacunas de categoria', 'mv-analytics' ); ?></h2>
								<p class="mv-an-card__hint"><?php esc_html_e( 'Clientes fiéis a uma categoria que nunca atravessaram para outra correlacionada. A receita potencial é um cenário conservador: uma unidade do carro-chefe da categoria por cliente, ao preço médio praticado.', 'mv-analytics' ); ?></p>
							</div>
							<div class="mv-an-card__body"><div data-mv="gaps"></div></div>
						</div>
					</div>

					<div class="mv-an-col-12">
						<div class="mv-an-card">
							<div class="mv-an-card__head">
								<h2 class="mv-an-card__title"><?php esc_html_e( 'Lista acionável', 'mv-analytics' ); ?></h2>
								<a class="mv-an-btn mv-an-btn--accent" href="<?php echo esc_url( $export ); ?>">
									<?php esc_html_e( 'Exportar CSV', 'mv-analytics' ); ?>
								</a>
								<p class="mv-an-card__hint"><?php esc_html_e( 'Cliente a abordar, produto a sugerir e o motivo — cada linha rastreável até a regra que a gerou. A lista é limitada de propósito: é para trabalhar, não para exportar a base inteira.', 'mv-analytics' ); ?></p>
							</div>
							<div class="mv-an-card__body"><div data-mv="oppo"></div></div>
						</div>
					</div>
				</div>
			</section>

			<?php endif; ?>
		</div>

		<!-- Ficha do cliente: gaveta lateral preenchida sob demanda. -->
		<div class="mv-an-drawer" data-mv="drawer" hidden>
			<div class="mv-an-drawer__scrim" data-mv="drawer-close"></div>
			<aside class="mv-an-drawer__panel" role="dialog" aria-modal="true" aria-labelledby="mv-an-drawer-title" tabindex="-1">
				<button type="button" class="mv-an-drawer__close" data-mv="drawer-close" aria-label="<?php esc_attr_e( 'Fechar', 'mv-analytics' ); ?>">×</button>
				<div class="mv-an-drawer__body" data-mv="drawer-body"></div>
			</aside>
		</div>
	</div>
	<?php
}

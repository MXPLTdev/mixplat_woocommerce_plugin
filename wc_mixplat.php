<?php
/*
Plugin Name: WooCommerce mixplat Payments Gateway
Plugin URI: https://mixplat.ru
Description: Mixplat Payments Gateway
Version: 1.0.0
Author: mixplat
Author URI: https://mixplat.ru
Copyright: © ООО «Миксплат Процессинг».
License: GNU General Public License v3.0
License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

class WC_Mixplatpayment
{

	private $settings;

	public function __construct()
	{

		$this->settings = get_option('woocommerce_mixplatpayment_settings');

		if (empty($this->settings['project_id'])) {
			$this->settings['project_id'] = '';
		}
		if (empty($this->settings['form_id'])) {
			$this->settings['form_id'] = '';
		}

		if (empty($this->settings['api_key'])) {
			$this->settings['api_key'] = '';
		}

		if (empty($this->settings['test_mode'])) {
			$this->settings['test_mode'] = '1';
		}

		if (empty($this->settings['hold'])) {
			$this->settings['hold'] = 'sms';
		}

		add_action('init', array($this, 'init_gateway'));
	}

	public function init_gateway()
	{
		global $woocommerce;

		if (!class_exists('WC_Payment_Gateway')) {
			return;
		}

		load_plugin_textdomain('woocommerce-gateway-mixplatpayment-payments', false, dirname(plugin_basename(__FILE__)) . '/languages/');
		load_plugin_textdomain('woocommerce-gateway-mixplatpaymentcard-payments', false, dirname(plugin_basename(__FILE__)) . '/languages/');

		include_once 'includes/class-wc-gateway-mixplatpayment.php';
		include_once 'includes/lib.php';

		add_filter('woocommerce_payment_gateways', array($this, 'add_gateway'));
		//add_filter( 'wc_order_statuses',  array( $this, 'add_status' ) );
		//add_filter( 'woocommerce_valid_order_statuses_for_payment_complete',  array( $this, 'add_valid_status' ) );

		if (empty($this->settings['project_id']) || $this->settings['enabled'] == 'no') {
			return;
		}

		// Disable for subscriptions until supported
		if (!is_admin() && class_exists('WC_Subscriptions_Cart') && WC_Subscriptions_Cart::cart_contains_subscription() && 'no' === get_option(WC_Subscriptions_Admin::$option_prefix . '_accept_manual_renewals', 'no')) {
			return;
		}

//		add_action( 'wp_enqueue_scripts', array( $this, 'scripts' ) );

		//add_filter( 'woocommerce_available_payment_gateways', array( $this, 'remove_gateways' ) );
	}

	public function add_gateway($methods)
	{
		$methods[] = 'WC_Gateway_Mixplatpayment';
		return $methods;
	}

	/**
	 * Remove all gateways except mixplatpayment
	 */
	/*public function remove_gateways( $gateways ) {
foreach ( $gateways as $gateway_key => $gateway ) {
if ( $gateway_key !== 'mixplatpayment' ) {
unset( $gateways[ $gateway_key ] );
}
}
return $gateways;
}*/

}
$GLOBALS['wc_mixplatpayment'] = new WC_Mixplatpayment();

function install_mixplatpayment()
{
	global $wpdb;

	$table_name = $wpdb->prefix . 'mixplatpayment';

	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE $table_name
(
	`id` varchar(36)  NOT NULL,
	`order_id` int(11) NOT NULL,
	`status` varchar(20)  NOT NULL,
	`status_extended` varchar(30)  NOT NULL,
	`date` datetime NOT NULL,
	`extra` text,
	`amount` int(11) NOT NULL,
	PRIMARY KEY (order_id)
) $charset_collate;
";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta($sql);
}

register_activation_hook(__FILE__, 'install_mixplatpayment');

add_action('admin_menu', 'mixplatpayment_menu');

function mixplatpayment_menu()
{
	$icon = 'data:image/svg+xml;base64,' . base64_encode(file_get_contents(dirname(__FILE__) . '/assets/images/icons/menu.svg'));
	add_menu_page('Mixplat: транзакции', 'Mixplat: транзакции', 'manage_woocommerce', 'mixplatpayment', "mixplatpayment_page_handler", $icon, '55.6');
}

function mixplatpayment_page_handler()
{
	mixplatpayment_transactions();
}

function mixplatpayment_return_payment()
{
	global $wpdb;
	$config = get_option('woocommerce_mixplatpayment_settings', null);
	$data   = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}mixplatpayment WHERE order_id=" . intval($_POST['order_id']));
	$query  = array(
		'payment_id' => $data->id,
		'amount'     => intval($_POST['sum'] * 100),
	);
	$query['signature'] = MixplatLib::calcActionSignature($query, $config['api_key']);
	return MixplatLib::refundPayment($query);
}

function mixplatpayment_confirm_payment()
{
	global $wpdb;
	$config = get_option('woocommerce_mixplatpayment_settings', null);
	$data   = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}mixplatpayment WHERE order_id=" . intval($_POST['order_id']));
	$amount = intval($_POST['sum'] * 100);
	$query  = array(
		'payment_id' => $data->id,
		'amount'     => $amount,
	);
	$query['signature'] = MixplatLib::calcActionSignature($query, $config['api_key']);
	MixplatLib::confirmPayment($query);
	$wpdb->query("UPDATE {$wpdb->prefix}mixplatpayment set status='success', status_extended='success_success',amount={$amount} where id='" . esc_sql($data->id) . "'");
	return true;
}

function mixplatpayment_cancel_payment()
{
	global $wpdb;
	$config = get_option('woocommerce_mixplatpayment_settings', null);
	$data   = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}mixplatpayment WHERE order_id=" . intval($_POST['order_id']));
	$query  = array(
		'payment_id' => $data->id,
	);
	$query['signature'] = MixplatLib::calcActionSignature($query, $config['api_key']);
	MixplatLib::cancelPayment($query);
	$wpdb->query("UPDATE {$wpdb->prefix}mixplatpayment set status='failure', status_extended='failure_canceled_by_merchant' where id='" . esc_sql($data->id) . "'");
	return true;
}

function mixplatpayment_transactions()
{
	global $wpdb;
	if (!current_user_can('manage_woocommerce')) {
		wp_die(__('You do not have sufficient permissions to access this page.'));
	}
	$message = '';
	$config  = get_option('woocommerce_mixplatpayment_settings', null);
	if (isset($_POST['action'])) {
		try {
			switch ($_POST['action']) {
				case 'return':mixplatpayment_return_payment();
					break;
				case 'cancel':mixplatpayment_cancel_payment();
					break;
				case 'confirm':mixplatpayment_confirm_payment();
					break;
			}
		} catch (Exception $e) {
			$message = $e->getMessage();
		}
	}

	$paged   = isset($_GET['paged']) ? intval($_GET['paged']) : 1;
	$perpage = 25;
	$from    = ($paged - 1) * $perpage;
	$where   = 'WHERE 1 = 1';
	if ((isset($_POST['filter_order']) && $_POST['filter_order'])) {
		$where = array();
		if ($_POST['filter_order']) {
			$where[] = 'order_id=' . intval($_POST['filter_order']);
		}
		$where = 'WHERE ' . implode(' and ', $where);
	}
	$transactions    = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}mixplatpayment $where order by order_id DESC limit $from,$perpage");
	$count           = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}mixplatpayment $where;");
	$total_items     = $count;
	$total_pages     = ceil($total_items / $perpage);
	$infinite_scroll = false;
	echo "<div class='wrap'>";
	if ($message) {
		echo '<div id="message" class="notice notice-info"><p>' . $message . '</p></div>';
	}
	echo "<h1>Платежные транзакции</h1>";
	if (!$count) {
		echo "- нет данных -";
		echo "</div>";
		return;
	}

	$output = '<span class="displaying-num">' . sprintf(_n('%s item', '%s items', $total_items), number_format_i18n($total_items)) . '</span>';

	$current = $paged;

	$removable_query_args = wp_removable_query_args();

	$current_url = set_url_scheme('http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);

	$current_url = remove_query_arg($removable_query_args, $current_url);

	$page_links = array();

	$total_pages_before = '<span class="paging-input">';
	$total_pages_after  = '</span></span>';

	$disable_first = $disable_last = $disable_prev = $disable_next = false;

	if ($current == 1) {
		$disable_first = true;
		$disable_prev  = true;
	}
	if ($current == 2) {
		$disable_first = true;
	}
	if ($current == $total_pages) {
		$disable_last = true;
		$disable_next = true;
	}
	if ($current == $total_pages - 1) {
		$disable_last = true;
	}

	if ($disable_first) {
		$page_links[] = '<span class="tablenav-pages-navspan" aria-hidden="true">&laquo;</span>';
	} else {
		$page_links[] = sprintf("<a class='first-page' href='%s'><span class='screen-reader-text'>%s</span><span aria-hidden='true'>%s</span></a>",
			esc_url(remove_query_arg('paged', $current_url)),
			__('First page'),
			'&laquo;'
		);
	}

	if ($disable_prev) {
		$page_links[] = '<span class="tablenav-pages-navspan" aria-hidden="true">&lsaquo;</span>';
	} else {
		$page_links[] = sprintf("<a class='prev-page' href='%s'><span class='screen-reader-text'>%s</span><span aria-hidden='true'>%s</span></a>",
			esc_url(add_query_arg('paged', max(1, $current - 1), $current_url)),
			__('Previous page'),
			'&lsaquo;'
		);
	}

	if ('bottom' === $which) {
		$html_current_page  = $current;
		$total_pages_before = '<span class="screen-reader-text">' . __('Current Page') . '</span><span id="table-paging" class="paging-input"><span class="tablenav-paging-text">';
	} else {
		$html_current_page = sprintf("%s<input class='current-page' id='current-page-selector' type='text' name='paged' value='%s' size='%d' aria-describedby='table-paging' /><span class='tablenav-paging-text'>",
			'<label for="current-page-selector" class="screen-reader-text">' . __('Current Page') . '</label>',
			$current,
			strlen($total_pages)
		);
	}
	$html_total_pages = sprintf("<span class='total-pages'>%s</span>", number_format_i18n($total_pages));
	$page_links[]     = $total_pages_before . sprintf(_x('%1$s of %2$s', 'paging'), $html_current_page, $html_total_pages) . $total_pages_after;

	if ($disable_next) {
		$page_links[] = '<span class="tablenav-pages-navspan" aria-hidden="true">&rsaquo;</span>';
	} else {
		$page_links[] = sprintf("<a class='next-page' href='%s'><span class='screen-reader-text'>%s</span><span aria-hidden='true'>%s</span></a>",
			esc_url(add_query_arg('paged', min($total_pages, $current + 1), $current_url)),
			__('Next page'),
			'&rsaquo;'
		);
	}

	if ($disable_last) {
		$page_links[] = '<span class="tablenav-pages-navspan" aria-hidden="true">&raquo;</span>';
	} else {
		$page_links[] = sprintf("<a class='last-page' href='%s'><span class='screen-reader-text'>%s</span><span aria-hidden='true'>%s</span></a>",
			esc_url(add_query_arg('paged', $total_pages, $current_url)),
			__('Last page'),
			'&raquo;'
		);
	}

	$pagination_links_class = 'pagination-links';
	if (!empty($infinite_scroll)) {
		$pagination_links_class = ' hide-if-js';
	}
	$output .= "\n<span class='$pagination_links_class'>" . join("\n", $page_links) . '</span>';

	if ($total_pages) {
		$page_class = $total_pages < 2 ? ' one-page' : '';
	} else {
		$page_class = ' no-pages';
	}
	echo '<div class="tablenav top">';
	?>
<form method="POST" action="">
<div class="alignleft actions bulkactions">
	<label class="screen-reader-text" for="search-transaction">Номер заказа:</label>
	<input placeholder="Номер заказа" type="search" id="search-transaction" class="wp-filter-search" name="filter_order" value="<?php echo htmlspecialchars(@$_POST['filter_order'], ENT_QUOTES, 'UTF-8'); ?>" />
	<?php submit_button('Фильтр', '', '', false, array('id' => 'search-submit'));?>
</div>
</form>
<?php
echo "<div class='tablenav-pages{$page_class}'>$output</div>";
	echo "</div>";
	$history_link = add_query_arg('section', 'history', $current_url);
	?>

<table class="wp-list-table widefat striped posts">
<thead>
	<tr>
		<th>Id платежа</th>
		<th>Id заказа</th>
		<th>Сумма заказа</th>
		<th>Дата транзакции</th>
		<th>Статус</th>
		<th>Действие</th>
	</tr>
	</thead>
	<tbody>
	<?php foreach ($transactions as $data):
		$amount = number_format(round($data->amount / 100, 2), 2, '.', '');
		?>
		<tr class="transactionrow" >
			<td><?php echo $data->id ?></td>
			<td><?php echo $data->order_id ?></td>
			<td><?php echo $amount ?></td>
			<td><?php echo $data->date ?></td>
			<td><?php
	$status = mixplatpayment_get_status_name($data->status, $data->status_extended);
		echo $status;
		?>
	    </td>
			<td><?php
	$currentStatus = $data->status;
		if ($data->status == 'pending') {
			$currentStatus = $data->status_extended;
		}
		$action = '';
		if (in_array($currentStatus, array("success", "pending_authorized")) && $data->amount > 0) {
			$action = '<form method="POST" action="">
	            <input type="text" name="sum" value="' . $amount . '" style="width:100%" size="9">
	            <input type="hidden" name="order_id" value="' . $data->order_id . '">
	            <br>';
			$action .= "<div style='white-space:nowrap'>";
			if (in_array($currentStatus, array("success"))) {
				$action .= '
	                <button class="button" type="submit" name="action" value="return">Возврат</button>';
			}
			if (in_array($currentStatus, array("pending_authorized"))) {
				$action .= '
	                <button class="button" type="submit" name="action" value="cancel">Отмена</button>';
				$action .= '
	                <button class="button" type="submit" name="action" value="confirm">Завершение</button>';
			}
			$action .= "</div>";
			$action .= '</form>';
		}
		echo $action;
		?>
	    </td>
		</tr>
	<?php endforeach;?>
	</tbody>
</table>
</div>
<?php
}

function mixplatpayment_get_status_name($status, $extended)
{
	if (in_array($status, array('pending', 'failure'))) {
		$status = $extended;
	}
	switch ($status) {
		case 'new':$name = 'Платеж создан';
			break;
		case 'pending':$name = 'Ожидается оплата';
			break;
		case 'success':$name = 'Оплачен';
			break;
		case 'failure_not_enough_money':$name = 'Платёж неуспешен: Недостаточно средств у плательщика';
			break;
		case 'failure_no_money':$name = 'Платёж неуспешен: Недостаточно средств у плательщика';
			break;
		case 'failure_gate_error':$name = 'Платёж неуспешен: Ошибка платёжного шлюза';
			break;
		case 'failure_canceled_by_user':$name = 'Платёж неуспешен: Отменён плательщиком';
			break;
		case 'failure_canceled_by_merchant':$name = 'Платёж неуспешен: Отменён ТСП';
			break;
		case 'failure_previous_payment':$name = 'Платёж неуспешен: Не завершён предыдущий платёж';
			break;
		case 'failure_not_available':$name = 'Платёж неуспешен: Услуга недоступна плательщику';
			break;
		case 'failure_accept_timeout':$name = 'Платёж неуспешен: Превышено время ожидания подтверждения платежа плательщиком';
			break;
		case 'failure_limits':$name = 'Платёж неуспешен: Превышены лимиты оплат';
			break;
		case 'failure_other':$name = 'Платёж неуспешен: Прочая ошибка';
			break;
		case 'failure_min_amount':$name = 'Платёж неуспешен: Сумма платежа меньше минимально допустимой';
			break;
		case 'failure_pending_timeout':$name = 'Платёж неуспешен: Превышено время обработки платежа';
			break;
		case 'pending_draft':$name = 'Платёж обрабатывается: Ещё не выбран платёжный метод';
			break;
		case 'pending_queued':$name = 'Платёж обрабатывается: Ожидание отправки в шлюз';
			break;
		case 'pending_processing':$name = 'Платёж обрабатывается: Обрабатывается шлюзом';
			break;
		case 'pending_check':$name = 'Платёж обрабатывается: Ожидается ответ ТСП на CHECK-запрос';
			break;
		case 'pending_authorized':$name = 'Платёж авторизован';
			break;
	}
	return $name;
}

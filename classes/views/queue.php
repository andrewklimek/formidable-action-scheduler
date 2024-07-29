<?php
$limit = isset( $_GET['all'] ) ? '' : 'LIMIT 100';
global $wpdb;
$queue = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}frm_actionscheduler_queue WHERE action_entry ORDER BY time $limit" );
$actions = [];
$endpoint = admin_url( 'admin-ajax.php' ) . '?nonce=' . wp_create_nonce('frm_ajax');
?>
<style>
	.frm-actionscheduler-queue-table .hide { opacity: .2; pointer-events: none; }
</style>
<table class="frm-actionscheduler-queue-table" style="width:100%">
	<tr>
		<th>Time
		<th>Action
		<th style="width:75px">Type
		<th style="width:75px">Form
		<th style="width:75px">Entry
			<th style="width:50px">Delete
	<?php
				// <th style="width:50px">Run
foreach ( $queue as $index => $event ) :
	$action_id = explode('_', $event->action_entry )[0];
	if ( ! is_numeric( $action_id ) ) continue;
	$action_id = (int) $action_id;
	$time = (int) $event->time;
	$entry_id = (int) explode('_', $event->action_entry )[1];
	if ( empty( $actions[ $action_id ] ) ) {
		$action = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}posts WHERE ID=" . $action_id );
		$actions[ $action_id ] = ['title' => esc_html( $action->post_title ), 'type' => esc_html( $action->post_excerpt ), 'form' => intval( $action->menu_order ) ];
	}
	echo "<tr data-timestamp={$time} data-entry_id={$entry_id} data-action_id={$action_id}>";
	echo "<td>" . esc_html( wp_date( 'Y-m-d H:i:s', $time ) );
	echo "<td>{$action_id} - " . $actions[ $action_id ]['title'];
	echo "<td>" . $actions[ $action_id ]['type'];
	echo "<td>" . $actions[ $action_id ]['form'];
	echo "<td><a href='". esc_url( admin_url( 'admin.php?page=formidable-entries&frm_action=show&id=' . $entry_id ) ) ."'>#{$entry_id}</a>";
	// echo "<td><a href='#' title='Run this Action' data-action=run>▶️</a>";
	echo "<td><a href='#' title='Delete this Action' data-action=delete>⏹️</a>";
endforeach;
?>
</table>
<?php if ( $limit ) echo "<p><a href='?all'>show all</a></p>"; ?>
<script>
(function(){
handleActions = function(e) {
	if( e.target.dataset.action == 'delete' ) {
		var row = e.target.closest('tr');
		if ( confirm( 'Are you sure you want to delete this action?' ) ) {
			fetch('<?php echo $endpoint ?>&action=formidable_autoresponder_delete_queue_item',{method:'POST',body: new URLSearchParams(row.dataset)})
			.then(r=>{return r.text()}).then(r=>{
				if ( r == 'ok' ) row.classList.add('hide');
			});
		}
	}
	else if( e.target.dataset.action == 'run' ) {
		var row = e.target.closest('tr');
		if ( confirm( 'Are you sure you want to run this action now?' ) ) {
			fetch('<?php echo $endpoint ?>&action=formidable_autoresponder_run_queue_item',{method:'POST', body: new URLSearchParams(row.dataset)})
			.then(r=>{return r.text()}).then(r=>{
			console.log(r);
			});
		}
	}
}
document.querySelector('.frm-actionscheduler-queue-table').addEventListener('click', handleActions );
})();
</script>
<pre><?php printf( __( 'Note: Times are in UTC +0, which is now %s', 'formidable-autoresponder' ), date( 'Y-m-d H:i:s' ) ) ?></pre>
<pre><?php echo wp_kses_post( $log ) ?></pre>
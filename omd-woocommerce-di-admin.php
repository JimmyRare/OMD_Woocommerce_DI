<h1>OMD Woocommerce DI</h1>

<?php settings_errors(); ?>

<form method="post" action="options.php">
    <?php settings_fields( 'omd-woocommerce-di' ); ?>
    <?php do_settings_sections( 'omd-woocommerce-di' ); ?>
    <?php submit_button(); ?>
</form>
<?php

/**
 * POINTERS CRAP.
 **/
add_action( 'admin_enqueue_scripts', 'iterative_admin_pointer_load', 1000 );
 
function iterative_admin_pointer_load( $hook_suffix ) {
 
    // Don't run on WP < 3.3
    if ( get_bloginfo( 'version' ) < '3.3' )
        return;
 
    $screen = get_current_screen();
    $screen_id = $screen->id;
 
    // Get pointers for this screen
    $pointers = apply_filters( 'iterative_admin_pointers-' . $screen_id, array() );
 
    if ( ! $pointers || ! is_array( $pointers ) )
        return;
 
    // Get dismissed pointers
    $dismissed = explode( ',', (string) get_user_meta( get_current_user_id(), 'dismissed_wp_pointers', true ) );
    $valid_pointers =array();
 
    // Check pointers and remove dismissed ones.
    foreach ( $pointers as $pointer_id => $pointer ) {
 
        // Sanity check
        if ( in_array( $pointer_id, $dismissed ) || empty( $pointer )  || empty( $pointer_id ) || empty( $pointer['target'] ) || empty( $pointer['options'] ) )
            continue;
 
        $pointer['pointer_id'] = $pointer_id;
 
        // Add the pointer to $valid_pointers array
        $valid_pointers['pointers'][] =  $pointer;
    }
 
    // No valid pointers? Stop here.
    if ( empty( $valid_pointers ) )
        return;
 
    // Add pointers style to queue.
    wp_enqueue_style( 'wp-pointer' );
 
    // Add pointers script to queue. Add custom script.
    wp_enqueue_script( 'iterative-pointer', plugins_url( 'js/pointer.js', __FILE__ ), array( 'wp-pointer' ) );
 
    // Add pointer options to script.
    wp_localize_script( 'iterative-pointer', 'iterativePointer', $valid_pointers );
}

add_filter( 'iterative_admin_pointers-post', 'iterative_register_admin_pointers' );
function iterative_register_admin_pointers( $p ) {
    $p['iterative_first_variant'] = array(
        'target' => '#iterative_first_variant',
        'options' => array(
            'content' => sprintf( '<h3> %s </h3> <p> %s </p>',
                __( ITERATIVE_HEADLINES_BRANDING ,'plugindomain'),
                __( 'Set up multiple post titles for testing here. You can have up to ten titles on each post: after an initial learning period, the best will be shown to your users. Learn more and configure what goal you wish to optimize for under <a href="options-general.php?page=headlines">Settings > Headlines</a>.','plugindomain')
            ),
            'position' => array( 'edge' => 'top', 'align' => 'left' )
        )
    );
    return $p;
}


?>
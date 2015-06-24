jQuery(document).ready( function($) {
    iterative_open_pointer(0);
    function iterative_open_pointer(i) {
        pointer = iterativePointer.pointers[i];
        options = $.extend( pointer.options, {
            close: function() {
                $.post( ajaxurl, {
                    pointer: pointer.pointer_id,
                    action: 'dismiss-wp-pointer'
                });
            }
        });

        $(pointer.target).pointer( options ).pointer('open');
    }
});

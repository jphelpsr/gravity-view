<?php
add_filter( 'gravityview_search_criteria', function( $criteria, $form_ids, $view_id ) {

    // Only apply to View ID 21
    if ( (int) $view_id !== 21 ) {
        return $criteria;
    }

    $criteria['search_criteria']['status'] = 'archived';

    return $criteria;

}, 10, 3 );
?>

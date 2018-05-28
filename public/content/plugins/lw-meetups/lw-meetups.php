<?php
/*
Plugin Name: Upcoming Meetups
Description: Lists upcoming meetups from LessWrong
Author: Taymon A. Beal
Author URI: https://anomalybeta.com
*/

class LW_Meetups_Widget extends WP_Widget {

	public function __construct() {
		parent::__construct(
			'lw_meetups',
			'Upcoming Meetups',
			array( 'description' => 'Lists upcoming meetups from LessWrong' )
		);
	}

	public function widget( $args, $instance ) {
		echo $args['before_widget'];
		if ( ! empty( $instance['title'] ) ) {
			echo $args['before_title'] . apply_filters( 'widget_title', 'Upcoming Meetups' ) . $args['after_title'];
		}
		echo esc_html__( 'Hello, World!', 'text_domain' );
		echo $args['after_widget'];
	}
}

add_action( 'widgets_init', function() {
	register_widget( 'LW_Meetups_Widget' );
});

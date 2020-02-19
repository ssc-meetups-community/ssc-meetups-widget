<?php
/*
Plugin Name: Slate Star Codex Meetups Widget
Plugin URI: https://github.com/ssc-meetups-community/ssc-meetups-widget
Description: Lists upcoming SSC meetups from LessWrong
License: MIT
*/

class SSC_Meetups_Widget extends WP_Widget {

	const DEFAULT_TITLE = 'Upcoming Meetups';
	const DEFAULT_MAX_COUNT = 5;
	const DEFAULT_MAX_DAYS_IN_FUTURE = 60;
	const CACHE_SECONDS = 900;
	const CACHE_KEY = 'ssc-meetups-v2';

	public function __construct() {
		parent::__construct( 'ssc_meetups', __( 'Slate Star Codex Meetups' ),
			array( 'description' => __( 'Lists upcoming SSC meetups from LessWrong.' ) )
		);
	}

	public function widget( $args, $instance ) {
		$title = apply_filters( 'widget_title',
			isset( $instance['title'] ) && ! empty( $instance['title'] )
			? $instance['title'] : __( self::DEFAULT_TITLE ),
			$instance, $this->id_base
		);
		$max_count = isset( $instance['max_count'] ) && ! empty( $instance['max_count'] )
			? absint( $instance['max_count'] ) : self::DEFAULT_MAX_COUNT;
		$max_days_in_future = isset( $instance['max_days_in_future'] ) && ! empty( $instance['max_days_in_future'] )
			? absint( $instance['max_days_in_future'] ) : self::DEFAULT_MAX_DAYS_IN_FUTURE;
		$all_meetups = get_transient( self::CACHE_KEY );
		if ( ! is_array( $all_meetups ) ) {
			$response = wp_remote_post( 'https://www.lesswrong.com/graphql', array(
					'body'    => '{"query": "{ posts(input: {terms: {view: \\"nearbyEvents\\", lat: 0, lng: 0, filters: \\"SSC\\"}}) { results { _id endTime googleLocation localStartTime slug startTime } } }"}',
					'headers' => array( 'Content-Type' => 'application/json' ),
				) );
			if ( ! is_wp_error( $response ) && $response['response']['code'] === 200 ) {
				$json = json_decode( $response['body'] );
				if ( isset( $json->data->posts->results ) && is_array( $json->data->posts->results ) ) {
					$all_meetups = array_filter( array_map( function( $post ) {
								if ( ! isset(
										$post->_id, $post->slug, $post->startTime,
										$post->googleLocation->address_components
									)
									|| ! is_string( $post->_id ) || ! is_string( $post->slug )
									|| ! is_string( $post->startTime ) || ! is_string( $post->localStartTime )
									|| ! preg_match( '/^[A-Za-z0-9]+$/', $post->_id )
									|| ! preg_match( '/^[a-z0-9-]+$/', $post->slug )
									|| ! is_array( $post->googleLocation->address_components ) ) {
									return false;
								}
								$start_time = date_create( $post->startTime );
								if ( ! $start_time ) {
									return false;
								}
								if ( isset( $post->endTime ) ) {
									if ( ! is_string( $post->endTime ) ) {
										return false;
									}
									$end_time = date_create( $post->endTime );
									if ( ! $end_time ) {
										return false;
									}
								} else {
									$end_time = NULL;
								}
								$local_start_time = date_create( $post->localStartTime );
								if ( ! $local_start_time ) {
									return false;
								}
								$locality = NULL;
								$area = NULL;
								$country = NULL;
								foreach ( $post->googleLocation->address_components as $component ) {
									if ( ! isset( $component->types ) || ! is_array( $component->types ) ) {
										return false;
									}
									if ( in_array( 'locality', $component->types )
										|| in_array( 'postal_town', $component->types )
										|| ( in_array( 'administrative_area_level_2', $component->types )
											&& ! $locality ) ) {
										if ( ! isset( $component->long_name ) || ! is_string( $component->long_name ) ) {
											return false;
										}
										$locality = $component->long_name;
									} elseif ( in_array( 'administrative_area_level_1', $component->types ) ) {
										if ( isset( $component->short_name ) ) {
											if ( ! is_string( $component->short_name ) ) {
												return false;
											}
											$area = $component->short_name;
										} else {
											if ( ! isset( $component->long_name )
												|| ! is_string( $component->long_name ) ) {
												return false;
											}
											$area = $component->long_name;
										}
									} elseif ( in_array( 'country', $component->types ) ) {
										if ( isset( $component->short_name ) ) {
											if ( ! is_string( $component->short_name ) ) {
												return false;
											}
											$country = $component->short_name;
										} else {
											if ( ! isset( $component->long_name )
												|| ! is_string( $component->long_name ) ) {
												return false;
											}
											$country = $component->long_name;
										}
									}
								}
								if ( ! $locality || ! $country ) {
									return false;
								}
								if ( ! in_array( $country, array( 'AU', 'CA', 'GB', 'US' ) ) ) {
									$area = NULL;
								}
								return array(
									'id'               => $post->_id,
									'slug'             => $post->slug,
									'start_time'       => $start_time,
									'end_time'         => $end_time,
									'local_start_time' => $local_start_time,
									'locality'         => $locality,
									'area'             => $area,
									'country'          => $country,
								);
							}, $json->data->posts->results ) );
					usort( $all_meetups, function( $a, $b ) {
							if ( $a['start_time'] != $b['start_time'] ) {
								return $a['start_time'] < $b['start_time'] ? -1 : 1;
							}
							if ( $a['country'] != $b['country'] ) {
								return $a['country'] < $b['country'] ? -1 : 1;
							}
							if ( isset( $a['area'] ) ) {
								if ( ! isset( $b['area'] ) ) {
									return 1;
								}
								if ( $a['area'] != $b['area'] ) {
									return $a['area'] < $b['area'] ? -1 : 1;
								}
							} else {
								if ( isset( $b['area'] ) ) {
									return -1;
								}
							}
							if ( $a['locality'] != $b['locality'] ) {
								return $a['locality'] < $b['locality'] ? -1 : 1;
							}
							return 0;
						} );
					set_transient( self::CACHE_KEY, $all_meetups, self::CACHE_SECONDS );
				}
			}
		}
		$now = date_create();
		$current_meetups = is_array( $all_meetups ) ? array_slice( array_filter( $all_meetups, function( $meetup ) use ( $now, $max_days_in_future ) {
					$delta = date_diff( $now, isset( $meetup['end_time'] ) ? $meetup['end_time'] : $meetup['start_time'] );
					return ! $delta->invert && $delta->days <= $max_days_in_future;
				} ), 0, $max_count )
			: NULL;
		echo $args['before_widget'];
		if ( $title ) {
			echo $args['before_title'] . htmlspecialchars($title) . $args['after_title'];
		}
		if ( $current_meetups ) {
?>
				<ul>
					<?php foreach ( $current_meetups as $meetup ) : ?>
						<li>
							<a href="https://www.lesswrong.com/events/<?php echo $meetup['id']; ?>/<?php echo $meetup['slug']; ?>"><?php echo $meetup['local_start_time']->format( 'M j' ); ?>: <?php echo htmlspecialchars($meetup['locality']) ?>, <?php if ( $meetup['area'] ) : echo htmlspecialchars($meetup['area']) ?>, <?php endif; echo htmlspecialchars($meetup['country']) ?></a>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php
		} else if ( is_array( $current_meetups ) ) {
?>
				<div>There are no upcoming meetups scheduled right now.</div>
			<?php
			} else {
?>
				<div>Sorry, the meetup list isn't available right now. Please refresh and try again. If the problem persists, please <a href="https://github.com/ssc-meetups-community/ssc-meetups-widget/issues/new">submit a bug report</a>.</div>
			<?php
		}
?>
			<ul style="padding-top: 0.5em;">
				<li><a href="https://www.lesswrong.com/community?filters=SSC">Map of Local Meetup Groups</a></li>
				<li><a href="https://www.lesswrong.com/newPost?eventForm=true&amp;ssc=true">Schedule a Meetup</a></li>
			</ul>
		<?php
		echo $args['after_widget'];
	}

	public function update( $new_instance, $old_instance ) {
		$instance                       = $old_instance;
		$instance['title']              = sanitize_text_field( $new_instance['title'] );
		$instance['max_count']          = (int) $new_instance['max_count'];
		$instance['max_days_in_future'] = (int) $new_instance['max_days_in_future'];
		return $instance;
	}

	public function form( $instance ) {
		$title     = isset( $instance['title'] ) ? esc_attr( $instance['title'] ) : self::DEFAULT_TITLE;
		$max_count = isset( $instance['max_count'] ) ? absint( $instance['max_count'] ) : self::DEFAULT_MAX_COUNT;
		$max_days_in_future = isset( $instance['max_days_in_future'] )
			? absint( $instance['max_days_in_future'] )
			: self::DEFAULT_MAX_DAYS_IN_FUTURE;
?>
			<p>
				<label for="<?php echo $this->get_field_id( 'title' ); ?>"><?php _e( 'Title:' ); ?></label>
				<input class="widefat" id="<?php echo $this->get_field_id( 'title' ); ?>"
				       name="<?php echo $this->get_field_name( 'title' ); ?>" type="text"
				       value="<?php echo htmlspecialchars($title); ?>" />
			</p>
			<p>
				<label for="<?php echo $this->get_field_id( 'max_count' ); ?>"><?php _e( 'Maximum number of meetups to show:' ); ?></label>
				<input class="tiny-text" id="<?php echo $this->get_field_id( 'max_count' ); ?>"
				       name="<?php echo $this->get_field_name( 'max_count' ); ?>" type="number" step="1" min="1"
				       value="<?php echo htmlspecialchars($max_count); ?>" size="3" />
			</p>
			<p>
				<label for="<?php echo $this->get_field_id( 'max_days_in_future' ); ?>"><?php _e( 'Maximum number of days in advance to show meetups:' ); ?></label>
				<input class="tiny-text" id="<?php echo $this->get_field_id( 'max_days_in_future' ); ?>"
				       name="<?php echo $this->get_field_name( 'max_days_in_future' ); ?>" type="number" step="1" min="1"
				       value="<?php echo htmlspecialchars($max_days_in_future); ?>" size="3" />
			</p>
		<?php
	}
}

add_action( 'widgets_init', function() {
		register_widget( 'SSC_Meetups_Widget' );
	} );

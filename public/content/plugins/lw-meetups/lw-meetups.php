<?php
/*
Plugin Name: LessWrong Meetups
Description: Lists upcoming meetups from LessWrong
Author: Taymon A. Beal
Author URI: https://anomalybeta.com
*/

class LW_Meetups_Widget extends WP_Widget {

	private const DEFAULT_TITLE = 'Upcoming Meetups';
	private const DEFAULT_MAX_COUNT = 5;
	private const DEFAULT_CACHE_SECONDS = 60;

	public function __construct() {
		parent::__construct( 'lw_meetups', __('LessWrong Meetups'), array(
			'description' => __( 'Lists upcoming meetups from LessWrong.' ),
		) );
	}

	public function widget( $args, $instance ) {
		$title = apply_filters( 'widget_title',
			( ! empty( $instance['title'] ) ) ? $instance['title'] : __( self::DEFAULT_TITLE ),
			$instance, $this->id_base
		);
		$max_count = ( ! empty( $instance['max_count'] ) )
				? absint( $instance['max_count'] )
				: self::DEFAULT_MAX_COUNT;
		$cache_seconds = ( ! empty( $instance['cache_seconds'] ) )
				? absint( $instance['cache_seconds'] )
				: self::DEFAULT_CACHE_SECONDS;

		$cache_key = 'lw-meetups-' . $max_count;
		$meetups = \TenUp\AsyncTransients\get_async_transient( $cache_key,
			function() use ( $max_count, $cache_seconds, $cache_key ) {
				$response = wp_remote_post('https://www.lesswrong.com/graphql', array(
					'body'    => json_encode( array( 'query'  => '
					query UpcomingMeetups {
						PostsList( terms: { view: "events", limit: ' . $maxCount . ' } ) {
							_id
							googleLocation
							slug
							startTime
						}
					}' ) ),
					'headers' => array( 'Content-Type' => 'application/json' ),
				) );
				$meetups = false;
				if ( ! is_wp_error( $response )	&& $response['response']['code'] === 200 ) {
					$json = json_decode( $response['body'] );
					if ( isset( $json->data->PostsList ) && is_array( $json->data->PostsList ) ) {
						$meetups = array_filter( array_map( function( $post ) {
							if ( ! isset(
									$post->_id, $post->slug, $post->startTime,
									$post->googleLocation->address_components
								)
									|| ! is_string( $post->_id) || ! is_string( $post->slug )
									|| ! is_string( $post->startTime )
									|| ! is_array( $post->googleLocation->address_components ) ) {
								return false;
							}
							$start_time = date_create( $post->startTime );
							if ( ! $start_time ) {
								return false;
							}
							$locality = NULL;
							$area = NULL;
							$country = NULL;
							foreach ( $post->googleLocation->address_components as $component ) {
								if ( ! isset( $component->types ) || ! is_array( $component->types ) ) {
									return false;
								}
								if ( in_array( 'locality', $component->types ) ) {
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
							return array(
								'id'         => $post->_id,
								'slug'       => $post->slug,
								'start_time' => $start_time,
								'locality'   => $locality,
								'area'       => $area,
								'country'    => $country,
							);
						}, $json->data->PostsList ) );
					}
				}
				\TenUp\AsyncTransients\set_async_transient( $cache_key, $meetups, $cache_seconds );
				return $meetups;
			}
		);

		echo $args['before_widget'];
		if ( $title ) {
			echo $args['before_title'] . $title . $args['after_title'];
		}
		if ( $meetups ) {
			?>
				<ul>
					<?php foreach ( $meetups as $meetup ) : ?>
						<li>
							<a href="https://www.lesswrong.com/events/<?php echo $meetup['id']; ?>/<?php echo $meetup['slug']; ?>"><?php echo $meetup['start_time']->format( 'F j' ); ?><br /><?php echo $meetup['locality'] ?>, <?php if ( $meetup['area'] ) : echo $meetup['area'] ?>, <?php endif; echo $meetup['country'] ?></a>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php
		} else {
			?>
				<div>There are no upcoming meetups scheduled right now.</div>
			<?php
		}
		?>
			<div><a href="https://www.lesswrong.com/newPost?eventForm=true">Schedule a Meetup</a></div>
		<?php
		echo $args['after_widget'];
	}

	public function update( $new_instance, $old_instance ) {
		$instance                  = $old_instance;
		$instance['title']         = sanitize_text_field( $new_instance['title'] );
		$instance['max_count']     = (int) $new_instance['max_count'];
		$instance['cache_seconds'] = (int) $new_instance['cache_seconds'];
		return $instance;
	}

	public function form( $instance ) {
		$title         = isset( $instance['title'] ) ? esc_attr( $instance['title'] ) : self::DEFAULT_TITLE;
		$max_count     = isset( $instance['max_count'] ) ? absint( $instance['max_count'] ) : self::DEFAULT_MAX_COUNT;
		$cache_seconds = isset( $instance['cache_seconds'] )
				? absint( $instance['cache_seconds'] )
				: self::DEFAULT_CACHE_SECONDS;
		?>
			<p>
				<label for="<?php echo $this->get_field_id( 'title' ); ?>"><?php _e( 'Title:' ); ?></label>
				<input class="widefat" id="<?php echo $this->get_field_id( 'title' ); ?>"
				       name="<?php echo $this->get_field_name( 'title' ); ?>" type="text"
				       value="<?php echo $title; ?>" />
			</p>
			<p>
				<label for="<?php echo $this->get_field_id( 'max_count' ); ?>"><?php _e( 'Maximum number of meetups to show:' ); ?></label>
				<input class="tiny-text" id="<?php echo $this->get_field_id( 'max_count' ); ?>"
				       name="<?php echo $this->get_field_name( 'max_count' ); ?>" type="number" step="1" min="1"
				       value="<?php echo $max_count; ?>" size="3" />
			</p>
			<p>
				<label for="<?php echo $this->get_field_id( 'cache_seconds' ); ?>"><?php _e( 'Number of seconds to remember LessWrong data for before checking again:' ); ?></label>
				<input class="tiny-text" id="<?php echo $this->get_field_id( 'cache_seconds' ); ?>"
				       name="<?php echo $this->get_field_name( 'cache_seconds' ); ?>" type="number" step="1" min="1"
				       value="<?php echo $cache_seconds; ?>" size="3" />
			</p>
		<?php
	}
}

add_action( 'widgets_init', function() {
	register_widget( 'LW_Meetups_Widget' );
} );

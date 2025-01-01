<?php defined( 'ABSPATH' ) || exit; ?>

<div class="fcal_calendar_wrap<?php echo esc_attr(isset($block) ? '_block' : ''); ?>">
    <div class="fluent_booking_wrap">
        <?php if (!isset($hideInfo) || !$hideInfo) { ?>
            <div class="fcal_author_header">
                <img src="<?php echo esc_url($author['avatar']); ?>"/>
                <div class="author_info">
                    <h1>
                        <?php echo esc_html($calendar->title); ?>
                    </h1>
                    <?php if ($calendar->description) { ?>
                        <p class="fcal_description"><?php echo wp_kses_post($calendar->description); ?></p>
                    <?php } ?>
                </div>
            </div>
        <?php } ?>
        <div class="fcal_slots_wrap">
            <div class="fcal_slots">
                <?php
                foreach ($events as $event): ?>
                    <div class="fcal_slot">
                        <a data-calendar_id="<?php echo (int)$event->calendar_id; ?>"
                           data-event_hash="<?php echo esc_attr($event->hash); ?>"
                           data-event_slug="<?php echo esc_attr($event->slug); ?>"
                           data-event_id="<?php echo (int)$event->id; ?>"
                           onclick="<?php echo 'faCalOpenBookingPage' . (isset($block) ? 'Block' : ''); ?>(this, event)"
                           href="<?php echo esc_url($event->public_url); ?>" class="fcal_card fcal_event_card">
                            <div class="fcal_slot_content">
                                <h2>
                                    <span class="fcal_slot_color_schema" style="background: <?php echo esc_attr($event->color_schema); ?>;"></span>
                                    <?php echo esc_html($event->title); ?>
                                </h2>
                                <p class="fcal_description"><?php echo esc_html($event->short_description); ?></p>
                                <div class="fcal_slot_items_wrap">
                                    <span class="fcal_slot_duration">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 14 14" fill="none">
                                            <path d="M12.8334 7C12.8334 10.22 10.22 12.8333 7.00002 12.8333C3.78002 12.8333 1.16669 10.22 1.16669 7C1.16669 3.78 3.78002 1.16666 7.00002 1.16666C10.22 1.16666 12.8334 3.78 12.8334 7Z" stroke="#445164" stroke-linecap="round" stroke-linejoin="round"/>
                                            <path d="M9.16418 8.855L7.35585 7.77584C7.04085 7.58917 6.78418 7.14 6.78418 6.7725V4.38084" stroke="#445164" stroke-linecap="round" stroke-linejoin="round"/>
                                        </svg>
                                        <?php if (count($event->durations) > 1) { ?>
                                            <?php echo esc_html__('Durations', 'fluent-booking'); ?>

                                            <div class="fcal_location_tooltip">
                                                    <?php foreach ($event->durations as $duration) { ?>
                                                        <span class="fcal_slot_duration">
                                                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 14 14" fill="none">
                                                                <path d="M12.8334 7C12.8334 10.22 10.22 12.8333 7.00002 12.8333C3.78002 12.8333 1.16669 10.22 1.16669 7C1.16669 3.78 3.78002 1.16666 7.00002 1.16666C10.22 1.16666 12.8334 3.78 12.8334 7Z" stroke="#445164" stroke-linecap="round" stroke-linejoin="round"/>
                                                                <path d="M9.16418 8.855L7.35585 7.77584C7.04085 7.58917 6.78418 7.14 6.78418 6.7725V4.38084" stroke="#445164" stroke-linecap="round" stroke-linejoin="round"/>
                                                            </svg>
                                                            <?php echo esc_html($duration); ?>
                                                        </span>
                                                    <?php } ?>
                                                </div>
                                        <?php } else {
                                            echo esc_html($event->durations[0]);
                                        } ?>
                                    </span>

                                    <span class="fcal_slot_location">
                                        <?php
                                        if (count($event->location_settings) > 1) { ?>
                                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-map-pin">
                                                <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z">
                                                </path><circle cx="12" cy="10" r="3"></circle>
                                            </svg>
                                            <?php echo esc_html__('Locations', 'fluent-booking'); ?>
                                            <span class="fcal_location_tooltip">
                                                <?php echo wp_kses_post($event->locations); ?>
                                            </span>
                                        <?php } else {
                                            echo wp_kses_post($event->locations);
                                        } ?>
                                    </span>

                                    <?php if ($event->payment_html) { ?>
                                        <?php echo $event->payment_html; ?>
                                    <?php } ?>

                                    <?php if ($event->event_time) { ?>
                                        <span class="fcal_slot_time">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 18 18" fill="none"><path d="M6 1.5V3.75" stroke="#445164" stroke-width="1.25" stroke-miterlimit="10" stroke-linecap="round" stroke-linejoin="round"></path><path d="M12 1.5V3.75" stroke="#445164" stroke-width="1.25" stroke-miterlimit="10" stroke-linecap="round" stroke-linejoin="round"></path><path d="M2.625 6.8175H15.375" stroke="#445164" stroke-miterlimit="10" stroke-linecap="round" stroke-linejoin="round"></path><path d="M15.75 6.375V12.75C15.75 15 14.625 16.5 12 16.5H6C3.375 16.5 2.25 15 2.25 12.75V6.375C2.25 4.125 3.375 2.625 6 2.625H12C14.625 2.625 15.75 4.125 15.75 6.375Z" stroke="#445164" stroke-miterlimit="10" stroke-linecap="round" stroke-linejoin="round"></path><path d="M11.771 10.275H11.7778" stroke="#445164" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path><path d="M11.771 12.525H11.7778" stroke="#445164" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path><path d="M8.99661 10.275H9.00335" stroke="#445164" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path><path d="M8.99661 12.525H9.00335" stroke="#445164" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path><path d="M6.22073 10.275H6.22747" stroke="#445164" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path><path d="M6.22073 12.525H6.22747" stroke="#445164" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path></svg>
                                            <?php echo esc_html(\FluentBooking\App\Services\DateTimeHelper::getFormattedEventTime($event->event_time)); ?>
                                        </span>
                                    <?php } ?>
                                </div>
                            </div>
                            <button class="book_now">
                                <?php esc_html_e('Book Now', 'fluent-booking'); ?>
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="21" viewBox="0 0 20 21" fill="none">
                                    <path d="M12.025 5.44167L17.0833 10.5L12.025 15.5583" stroke="#306AE0" stroke-width="1.5" stroke-miterlimit="10" stroke-linecap="round" stroke-linejoin="round"/>
                                    <path d="M2.91666 10.5H16.9417" stroke="#306AE0" stroke-width="1.5" stroke-miterlimit="10" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </button>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

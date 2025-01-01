<?php defined( 'ABSPATH' ) || exit; ?>

<div class="fcal_container">
    <div class="fcal_booking_header">
        <?php if ($attributes['title']) : ?>
            <h2><?php echo esc_html($attributes['title']) ?></h2>
        <?php endif; ?>
        <?php if ($attributes['filter'] == 'show') : ?>
            <div class="fcal_booking_header_actions">
                <form action="" method="GET">
                    <?php foreach ($period_options as $value => $label): ?>
                        <div class="fcal_radio_btn">
                            <input type="radio" name="booking_period" id="fcal_period_<?php echo esc_attr($value) ?>"
                                value="<?php echo esc_attr($value) ?>" <?php echo $booking_period == $value ? 'checked' : '' ?>
                                onchange="this.form.submit()">
                            <label for="fcal_period_<?php echo esc_attr($value) ?>"><?php echo esc_html($label) ?></label>
                        </div>
                    <?php endforeach; ?>
                    <input type="hidden" name="booking_per_page" value="<?php echo esc_attr($per_page); ?>">
                </form>
            </div>
        <?php endif; ?>
    </div>
    <div class="fcal_all_bookings">
        <div class="fcal_bookings">
            <div class="fcal_booking_wrapper">
                <?php foreach ($bookings as $booking) : ?>
                    <div class="fcal_booking" onclick="location.href='<?php echo esc_url($booking->getConfirmationUrl()); ?>'">
                        <div class="fcal_spot_wrapper <?php echo 'fcal_spot_status_' . esc_attr($booking->status) ?>">
                            <div class="fcal_spot_line">
                                <div class="fcal_spot_timing">
                                    <p class="fcal_booking_date"><?php echo esc_html($booking->booking_date); ?></p>
                                    <p class="fcal_booking_time"><?php echo esc_html($booking->booking_time); ?></p>
                                    <p class="fcal_booking_timezone">(<?php echo esc_html($booking->person_time_zone); ?>)</p>
                                </div>
                                <div class="fcal_spot_desc">
                                <h3 class="fcal_spot_title">
                                    <?php echo wp_kses_post($booking->getBookingTitle(true)); ?>
                                </h3>
                                    <div class="fcal_spot_desc_sub_info">
                                        <?php if ($booking->happening_status) : ?>
                                            <?php foreach ($booking->happening_status as $slug => $status) : ?>
                                                <div class="fcal_spot_happening">
                                                    <span class=<?php echo 'fcal_' . esc_attr($slug) ?>>
                                                        <?php echo esc_html($status) ?>
                                                    </span>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php else : ?>
                                            <span class="fcal_spot_period_status">
                                                <?php echo esc_html($booking->booking_status_text) ?>
                                            </span>
                                        <?php endif; ?>

                                        <?php if ($booking->payment_status) : ?>
                                            <p class="fcal_spot_payment_status <?php echo esc_attr($booking->payment_status) ?>">
                                                <?php echo esc_html($booking->payment_status_text) ?>
                                            </p>
                                        <?php endif; ?>

                                        <?php if ($booking->status == 'pending' && $booking->payment_status != 'pending') : ?>
                                            <p class="fcal_spot_period_status unconfirmed">
                                                <?php esc_html_e('Unconfirmed', 'fluent-booking') ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="fcal_spot_actions">
                                    <button class="fcal_plain_btn"
                                        onclick="location.href='<?php echo esc_url($booking->getConfirmationUrl()); ?>'">
                                        <?php esc_html_e('View', 'fluent-booking')?>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if ($bookings->isEmpty()): ?>
                    <div class="fcal_no_bookings">
                        <p><?php echo esc_html($attributes['no_bookings']) ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($attributes['pagination'] == 'show' && $bookings->lastPage() > 1): ?>
        <ul class="fcal_pagination">
            <span><?php echo esc_html(__('Total', 'fluent-booking') . ' ' . $bookings->total())?></span>

            <form action="" method="GET">
                <select name="booking_per_page" id="fcal_booking_per_page" onchange="this.form.submit()">
                    <?php foreach ($page_options as $option): ?>
                        <option value="<?php echo esc_attr($option); ?>" <?php echo ($per_page == $option) ? 'selected' : ''; ?>>
                            <?php echo esc_html($option) . '/' . esc_html__('page', 'fluent-booking') ?>
                        </option>
                    <?php endforeach; ?>
                    <input type="hidden" name="booking_period" value="<?php echo esc_attr($booking_period); ?>">
                </select>
            </form>

            <?php if ($bookings->onFirstPage()): ?>
                <a class="fcal_btn prev disabled" aria-label="Previous page is disabled" role="button">«</a>
            <?php else: ?>
                <a class="fcal_btn prev" aria-label="Go to previous page" role="button"
                    href="<?php echo esc_url($bookings->previousPageUrl()); ?>">«
                </a>
            <?php endif; ?>

            <ul class="fcal_pager">
                <?php for ($page = $start_page; $page <= $end_page; $page++): ?>
                    <?php if ($page == $bookings->currentPage()): ?>
                        <li class="active"><span><?php echo esc_html($page); ?></span></li>
                    <?php else: ?>
                        <li><a aria-label="Go to page <?php echo esc_attr($page); ?>"
                            href="<?php echo esc_url($bookings->url($page)); ?>"><?php echo esc_html($page); ?></a>
                        </li>
                    <?php endif; ?>
                <?php endfor; ?>
            </ul>

            <?php if ($bookings->hasMorePages()): ?>
                <a class="fcal_btn next" aria-label="Go to next page" role="button"
                    href="<?php echo esc_url($bookings->nextPageUrl()); ?>">»
                </a>
            <?php else: ?>
                <a class="fcal_btn next disabled" aria-label="Next page is disabled" role="button">»</a>
            <?php endif; ?>
        </ul>
    <?php endif; ?>
</div>

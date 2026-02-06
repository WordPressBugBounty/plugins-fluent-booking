<?php defined( 'ABSPATH' ) || exit; ?>

<div id="<?php echo esc_attr($slug); ?>-app" class="warp fconnector_app">
    <div class="fframe_app">
        <div class="fframe_main-menu-items">
            <div class="fframe_handheld">
                <button aria-label="Open Menu" class="fcal_menu_opener_btn" aria-disabled="false" type="button" onclick="toggleMobileMenu()">
                    <i class="el-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="48" height="48" color="currentColor" fill="none"> <path fill-rule="evenodd" clip-rule="evenodd" d="M3 4.5C3 3.94772 3.44772 3.5 4 3.5L20 3.5C20.5523 3.5 21 3.94772 21 4.5C21 5.05229 20.5523 5.5 20 5.5L4 5.5C3.44772 5.5 3 5.05228 3 4.5Z" fill="currentColor"></path><path fill-rule="evenodd" clip-rule="evenodd" d="M3 14.5C3 13.9477 3.44772 13.5 4 13.5L20 13.5C20.5523 13.5 21 13.9477 21 14.5C21 15.0523 20.5523 15.5 20 15.5L4 15.5C3.44772 15.5 3 15.0523 3 14.5Z" fill="currentColor"></path><path fill-rule="evenodd" clip-rule="evenodd" d="M3 9.5C3 8.94772 3.44772 8.5 4 8.5L20 8.5C20.5523 8.5 21 8.94772 21 9.5C21 10.0523 20.5523 10.5 20 10.5L4 10.5C3.44772 10.5 3 10.0523 3 9.5Z" fill="currentColor"></path><path fill-rule="evenodd" clip-rule="evenodd" d="M3 19.5C3 18.9477 3.44772 18.5 4 18.5L20 18.5C20.5523 18.5 21 18.9477 21 19.5C21 20.0523 20.5523 20.5 20 20.5L4 20.5C3.44772 20.5 3 20.0523 3 19.5Z" fill="currentColor"></path></svg>
                    </i>
                </button>
                <ul class="fframe_menu fcal_nav fcal_mobile_menu" id="fcal_mobile_menu_list">
                    <?php foreach ($menuItems as $fluentBookingMenuItem): ?>
                        <li data-key="<?php echo esc_attr($fluentBookingMenuItem['key']); ?>" class="fframe_menu_item fframe_item_<?php echo esc_attr($fluentBookingMenuItem['key']); ?>">
                            <a class="fframe_menu_primary" onclick="toggleMobileMenu()" href="<?php echo esc_url($fluentBookingMenuItem['permalink']); ?>">
                                <?php echo esc_attr($fluentBookingMenuItem['label']); ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <div class="menu_logo_holder">
                <a href="<?php echo esc_url($baseUrl); ?>">
                    <img class="fcal_light_logo" src="<?php echo esc_url($logo); ?>"
                         alt="<?php echo esc_attr__('Logo', 'fluent-booking'); ?>"/>
                    <img class="fcal_dark_logo" src="<?php echo esc_url($dark_logo); ?>"
                         alt="<?php echo esc_attr__('Logo', 'fluent-booking'); ?>"/>
                </a>
            </div>

            <ul class="fframe_menu fcal_nav">
				<?php foreach ($menuItems as $fluentBookingMenuItem): ?>
					<?php $fluentBookingHasSubMenu = !empty($fluentBookingMenuItem['sub_items']); ?>
                    <li data-key="<?php echo esc_attr($fluentBookingMenuItem['key']); ?>" class="fframe_menu_item <?php echo ($fluentBookingHasSubMenu) ? 'fframe_has_sub_items' : ''; ?> fframe_item_<?php echo esc_attr($fluentBookingMenuItem['key']); ?>">
                        <a class="fframe_menu_primary" href="<?php echo esc_url($fluentBookingMenuItem['permalink']); ?>">
							<?php echo esc_attr($fluentBookingMenuItem['label']); ?>
							<?php if($fluentBookingHasSubMenu){ ?>
                                <span class="dashicons dashicons-arrow-down-alt2"></span>
							<?php } ?></a>
						<?php if($fluentBookingHasSubMenu): ?>
                            <div class="fframe_submenu_items">
								<?php foreach ($fluentBookingMenuItem['sub_items'] as $fluentBookingSubItem): ?>
                                    <a href="<?php echo esc_url($fluentBookingSubItem['permalink']); ?>"><?php echo esc_attr($fluentBookingSubItem['label']); ?></a>
								<?php endforeach; ?>
                            </div>
						<?php endif; ?>
                    </li>
				<?php endforeach; ?>
            </ul>

            <div class="fframe_settings">
                <div class="settings_menu_wrapper" data-key="color_mode">
                    <a class="settings_menu fcal_color_mode" onclick="toggleColorMode()">
                        <span class="el-icon">
                            <svg class="fcal_light_icon" width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M17.9163 11.7317C16.9166 12.2654 15.7748 12.5681 14.5623 12.5681C10.6239 12.5681 7.43128 9.37543 7.43128 5.43705C7.43128 4.22456 7.73388 3.08274 8.2677 2.08301C4.72272 2.91382 2.08301 6.09562 2.08301 9.89393C2.08301 14.3246 5.67476 17.9163 10.1054 17.9163C13.9038 17.9163 17.0855 15.2767 17.9163 11.7317Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            <svg class="fcal_dark_icon" width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><g clip-path="url(#clip0_2906_20675)">
                                <path d="M14.1663 10.0007C14.1663 12.3018 12.3009 14.1673 9.99967 14.1673C7.69849 14.1673 5.83301 12.3018 5.83301 10.0007C5.83301 7.69946 7.69849 5.83398 9.99967 5.83398C12.3009 5.83398 14.1663 7.69946 14.1663 10.0007Z" stroke="currentColor" stroke-width="1.5"/> <path d="M9.99984 1.66699V2.91699M9.99984 17.0837V18.3337M15.8922 15.8931L15.0083 15.0092M4.99089 4.99137L4.107 4.10749M18.3332 10.0003H17.0832M2.9165 10.0003H1.6665M15.8926 4.10758L15.0087 4.99147M4.99129 15.0093L4.10741 15.8932" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></g><defs><clipPath id="clip0_2906_20675"><rect width="20" height="20" fill="currentColor"/></clipPath></defs>
                            </svg>
                        </span>
                    </a>
                </div>
                <?php if(!empty($rightItems)): ?>
                    <ul class="fframe_menu fcal_secondary_menu">
                        <?php foreach ($rightItems as $fluentBookingMenuItem): ?>
                            <?php $fluentBookingHasSubMenu = !empty($fluentBookingMenuItem['sub_items']); ?>
                            <li data-key="<?php echo esc_attr($fluentBookingMenuItem['key']); ?>" class="fframe_menu_item <?php echo ($fluentBookingHasSubMenu) ? 'fframe_has_sub_items' : ''; ?> fframe_item_<?php echo esc_attr($fluentBookingMenuItem['key']); ?>">
                                <a class="fframe_menu_primary" href="<?php echo esc_url($fluentBookingMenuItem['permalink']); ?>">
                                    <?php echo esc_attr($fluentBookingMenuItem['label']); ?>
                                    <?php if($fluentBookingHasSubMenu){ ?>
                                        <span class="dashicons dashicons-arrow-down-alt2"></span>
                                    <?php } ?></a>
                                <?php if($fluentBookingHasSubMenu): ?>
                                    <div class="fframe_submenu_items">
                                        <?php foreach ($fluentBookingMenuItem['sub_items'] as $fluentBookingSubItem): ?>
                                            <a href="<?php echo esc_url($fluentBookingSubItem['permalink']); ?>"><?php echo esc_attr($fluentBookingSubItem['label']); ?></a>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if(!empty($settings)): ?>
                    <ul class="fcal_nav settings_menu_wrapper">
                        <li class="fframe_menu_item fframe_item_<?php echo esc_attr($settings['key']); ?>">
                            <a href="<?php echo esc_url($settings['permalink']); ?>" class="settings_menu">
                                <span class="el-icon">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1024 1024"><path fill="currentColor" d="M600.704 64a32 32 0 0 1 30.464 22.208l35.2 109.376c14.784 7.232 28.928 15.36 42.432 24.512l112.384-24.192a32 32 0 0 1 34.432 15.36L944.32 364.8a32 32 0 0 1-4.032 37.504l-77.12 85.12a357.12 357.12 0 0 1 0 49.024l77.12 85.248a32 32 0 0 1 4.032 37.504l-88.704 153.6a32 32 0 0 1-34.432 15.296L708.8 803.904c-13.44 9.088-27.648 17.28-42.368 24.512l-35.264 109.376A32 32 0 0 1 600.704 960H423.296a32 32 0 0 1-30.464-22.208L357.696 828.48a351.616 351.616 0 0 1-42.56-24.64l-112.32 24.256a32 32 0 0 1-34.432-15.36L79.68 659.2a32 32 0 0 1 4.032-37.504l77.12-85.248a357.12 357.12 0 0 1 0-48.896l-77.12-85.248A32 32 0 0 1 79.68 364.8l88.704-153.6a32 32 0 0 1 34.432-15.296l112.32 24.256c13.568-9.152 27.776-17.408 42.56-24.64l35.2-109.312A32 32 0 0 1 423.232 64H600.64zm-23.424 64H446.72l-36.352 113.088-24.512 11.968a294.113 294.113 0 0 0-34.816 20.096l-22.656 15.36-116.224-25.088-65.28 113.152 79.68 88.192-1.92 27.136a293.12 293.12 0 0 0 0 40.192l1.92 27.136-79.808 88.192 65.344 113.152 116.224-25.024 22.656 15.296a294.113 294.113 0 0 0 34.816 20.096l24.512 11.968L446.72 896h130.688l36.48-113.152 24.448-11.904a288.282 288.282 0 0 0 34.752-20.096l22.592-15.296 116.288 25.024 65.28-113.152-79.744-88.192 1.92-27.136a293.12 293.12 0 0 0 0-40.256l-1.92-27.136 79.808-88.128-65.344-113.152-116.288 24.96-22.592-15.232a287.616 287.616 0 0 0-34.752-20.096l-24.448-11.904L577.344 128zM512 320a192 192 0 1 1 0 384 192 192 0 0 1 0-384zm0 64a128 128 0 1 0 0 256 128 128 0 0 0 0-256z"></path></svg>
                                </span>
                                <span class="settings_menu_text"><?php echo esc_attr($settings['label']); ?></span>
                            </a>
                        </li>
                    </ul>
                    <?php endif; ?>
                </div>
                <div class="fcal_mobile_settings_menu_opener">
                    <button aria-label="Open Menu" class="fcal_menu_opener_btn" aria-disabled="false" type="button" onclick="toggleMobileSettingsMenu()">
                        <i class="el-icon">
                            <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M3.25 4H16.75V5.5H3.25V4ZM3.25 9.25H12.25V10.75H3.25V9.25ZM3.25 14.5H16.75V16H3.25V14.5Z" fill="currentColor"/></svg>
                        </i>
                    </button>
                </div>
            </div>
        <div class="fframe_body">
            <div id="fluent-framework-app" class="fs_route_wrapper"></div>
        </div>
    </div>
</div>

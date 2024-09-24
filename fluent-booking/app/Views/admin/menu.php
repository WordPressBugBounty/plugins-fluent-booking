<?php defined( 'ABSPATH' ) || exit; ?>

<div id="<?php echo esc_attr($slug); ?>-app" class="warp fconnector_app">
    <div class="fframe_app">
        <div class="fframe_main-menu-items">
            <div class="menu_logo_holder">
                <a href="<?php echo esc_url($baseUrl); ?>">
                    <img style="max-height: 40px;" src="<?php echo esc_url($logo); ?>" />
                </a>
            </div>
            <div class="fframe_handheld">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20"><path fill="currentColor" d="M20 5V2H0v3h20zm0 6V8H0v3h20zm0 6v-3H0v3h20z"/></svg>
            </div>

            <ul class="fframe_menu">
				<?php foreach ($menuItems as $item): ?>
					<?php $hasSubMenu = !empty($item['sub_items']); ?>
                    <li data-key="<?php echo esc_attr($item['key']); ?>" class="fframe_menu_item <?php echo ($hasSubMenu) ? 'fframe_has_sub_items' : ''; ?> fframe_item_<?php echo esc_attr($item['key']); ?>">
                        <a class="fframe_menu_primary" href="<?php echo esc_url($item['permalink']); ?>">
							<?php echo esc_attr($item['label']); ?>
							<?php if($hasSubMenu){ ?>
                                <span class="dashicons dashicons-arrow-down-alt2"></span>
							<?php } ?></a>
						<?php if($hasSubMenu): ?>
                            <div class="fframe_submenu_items">
								<?php foreach ($item['sub_items'] as $sub_item): ?>
                                    <a href="<?php echo esc_url($sub_item['permalink']); ?>"><?php echo esc_attr($sub_item['label']); ?></a>
								<?php endforeach; ?>
                            </div>
						<?php endif; ?>
                    </li>
				<?php endforeach; ?>
            </ul>

            <?php if(!empty($rightItems)): ?>
                <ul class="fframe_menu fcal_secondary_menu">
                    <?php foreach ($rightItems as $item): ?>
                        <?php $hasSubMenu = !empty($item['sub_items']); ?>
                        <li data-key="<?php echo esc_attr($item['key']); ?>" class="fframe_menu_item <?php echo ($hasSubMenu) ? 'fframe_has_sub_items' : ''; ?> fframe_item_<?php echo esc_attr($item['key']); ?>">
                            <a class="fframe_menu_primary" href="<?php echo esc_url($item['permalink']); ?>">
                                <?php echo esc_attr($item['label']); ?>
                                <?php if($hasSubMenu){ ?>
                                    <span class="dashicons dashicons-arrow-down-alt2"></span>
                                <?php } ?></a>
                            <?php if($hasSubMenu): ?>
                                <div class="fframe_submenu_items">
                                    <?php foreach ($item['sub_items'] as $sub_item): ?>
                                        <a href="<?php echo esc_url($sub_item['permalink']); ?>"><?php echo esc_attr($sub_item['label']); ?></a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <div class="fframe_body">
            <div id="fluent-framework-app" class="fs_route_wrapper"></div>
        </div>
    </div>
</div>

<?php

class FluentBookingCustomGroupSelect extends \Elementor\Base_Control {

    public function get_type() {
        return 'fcal_group_select';
    }

    protected function get_default_settings() {
        return [
            'label_block' => true,
            'options'     => [],
        ];
    }

    public function content_template() {
        ?>
        <div class="elementor-control-field">
            <label for="{{ data.controlUid }}" class="elementor-control-title">{{{ data.label }}}</label>
            <# var options = data.options; #>
            <div class="elementor-control-input-wrapper">
                <select class="fcal-group-select" id="{{ data.controlUid }}" data-setting="{{ data.name }}" name="{{ data.name }}">
                    <# _.each( data.options, function( group_options, group_label ) { #>
                    <optgroup label="{{{ group_label }}}">
                        <# _.each( group_options, function( label, value ) { #>
                        <option value="{{ value }}">{{{ label }}}</option>
                        <# }); #>
                    </optgroup>
                    <# }); #>
                </select>
            </div>
        </div>
        <?php
    }

}

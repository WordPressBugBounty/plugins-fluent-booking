<?php defined('ABSPATH') || exit; ?>

<!-- CSS styles for fluent booking payment receipt -->
<style>
    .fluent_booking_payment_receipt{
        display: block !important;
        padding: 16px;
        background: #f8f8f8;
        border-radius: 13px;
    }
    .fluent_booking_table {
        width: 100%;
        empty-cells: show;
        font-size: 14px;
        border: 1px solid var(--fcal_slot_border) !important;
    }

    .fluent_booking_table td, .fluent_booking_table th {
        border-left: 1px solid var(--fcal_slot_border);
        border-width: 0 0 0 1px;
        font-size: inherit;
        margin: 0;
        overflow: visible;
        padding: .5em 1em;
        color: var(--fcal_dark);
    }

    .fluent_booking_table td:first-child, .fluent_booking_table th:first-child {
        border-left-width: 0
    }

    .fluent_booking_table thead {
        background-color: #e3e8ee;
        color: #000;
        text-align: left;
        vertical-align: bottom
    }

    .fluent_booking_table td {
        background-color: transparent
    }

    .fluent_booking_table tfoot {
        border-top: 1px solid #cbcbcb;
    }

    table.input_items_table {
        border-collapse: collapse;
    }

    table.input_items_table tr td, table.input_items_table tr th {
        color: var(--fcal_dark);
        border: 1px solid var(--fcal_slot_border);
        text-align: left;
        width: auto;
        word-break: normal;
    }

    table.input_items_table tr th {
        min-width: 20%;
    }

    .fluent_booking_payment_info {
        width: 100%;
        border-top: 2px solid var(--fcal_slot_border);
        background-color: var(--fcal_thBG);
        color: var(--fcal_dark);
        td {
            /*color: var(--fcal_dark);*/
        }
    }

    .fluent_booking_payment_info_item {
        display: inline-block;
        margin-right: 0px;
        -webkit-box-shadow: inset -1px 0 #e3e8ee;
        box-shadow: inset -1px 0 #e3e8ee;
        padding: 12px;
    }

    .fluent_booking_payment_info_item:last-child {
        box-shadow: none;
    }

    .fluent_booking_payment_info_item .fluent_booking_item_heading {
        font-size: 14px;
        font-weight: bold;
    }

    .fluent_booking_payment_info_item .fluent_booking_item_value {
        font-size: 14px;
    }

    .fluent_booking_payment_receipt h4 {
        font-size: 18px;
    }

    .fluent_booking_order_items_table {
        border-collapse: collapse;
    }

    .fluent_booking_order_items_table tr {
        border: 1px solid var(--fcal_slot_border);
    }
</style>

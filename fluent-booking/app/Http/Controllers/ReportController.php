<?php

namespace FluentBooking\App\Http\Controllers;

use FluentBooking\App\Models\Booking;
use FluentBooking\App\Models\BookingActivity;
use FluentBooking\App\Services\ReportingHelperTrait;
use FluentBooking\Framework\Http\Request\Request;
use FluentBooking\Framework\Support\Arr;
use FluentBooking\App\Services\DateTimeHelper;
use FluentBooking\App\Services\PermissionManager;
use FluentBooking\Framework\Validator\ValidationException;

class ReportController extends Controller
{
    use ReportingHelperTrait;

    public function getReports(Request $request)
    {
        $startTime = $request->get('startTime');
        $endTime = $request->get('endTime');

        if ($startTime && $endTime) {
            $timeZone = DateTimeHelper::getTimeZone();
            $startTime = DateTimeHelper::convertToUtc($startTime, $timeZone);
            $endTime = DateTimeHelper::convertToUtc($endTime, $timeZone);

            $bookingWidgetNumbers = $this->getBookingWidgetNumbers($startTime, $endTime);
        } else {
            $startTime = gmdate('Y-m-d H:i:s', strtotime('-30 days')); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
            $endTime = gmdate('Y-m-d H:i:s', strtotime('now UTC')); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date

            $bookingWidgetNumbers = $this->getAllBookingWidgetNumbers();
        }

        $bookingWidgetStats = $this->getBookingWidgetStats($startTime, $endTime);

        $paymentWidget = $this->getPaymentWidgets($startTime, $endTime);

        $widgets = [
            [
                'title'   => __('Total Bookings', 'fluent-booking'),
                'period'  => 'all',
                'number'  => $bookingWidgetNumbers['totalBooked'],
                'content' => $bookingWidgetStats['bookedComparison'],
                'icon'    => '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 40 40" fill="none">
                                <path d="M27.9166 5.93398V3.33398C27.9166 2.65065 27.35 2.08398 26.6666 2.08398C25.9833 2.08398 25.4166 2.65065 25.4166 3.33398V5.83398H14.5833V3.33398C14.5833 2.65065 14.0166 2.08398 13.3333 2.08398C12.65 2.08398 12.0833 2.65065 12.0833 3.33398V5.93398C7.58331 6.35065 5.39998 9.03398 5.06664 13.0173C5.03331 13.5007 5.43331 13.9007 5.89998 13.9007H34.1C34.5833 13.9007 34.9833 13.484 34.9333 13.0173C34.6 9.03398 32.4166 6.35065 27.9166 5.93398Z" fill="white"/>
                                <path d="M33.3333 16.4004H6.66667C5.75 16.4004 5 17.1504 5 18.0671V28.3337C5 33.3337 7.5 36.6671 13.3333 36.6671H26.6667C32.5 36.6671 35 33.3337 35 28.3337V18.0671C35 17.1504 34.25 16.4004 33.3333 16.4004ZM15.35 30.3504C15.1833 30.5004 15 30.6171 14.8 30.7004C14.6 30.7837 14.3833 30.8337 14.1667 30.8337C13.95 30.8337 13.7333 30.7837 13.5333 30.7004C13.3333 30.6171 13.15 30.5004 12.9833 30.3504C12.6833 30.0337 12.5 29.6004 12.5 29.1671C12.5 28.7337 12.6833 28.3004 12.9833 27.9837C13.15 27.8337 13.3333 27.7171 13.5333 27.6337C13.9333 27.4671 14.4 27.4671 14.8 27.6337C15 27.7171 15.1833 27.8337 15.35 27.9837C15.65 28.3004 15.8333 28.7337 15.8333 29.1671C15.8333 29.6004 15.65 30.0337 15.35 30.3504ZM15.7 23.9671C15.6167 24.1671 15.5 24.3504 15.35 24.5171C15.1833 24.6671 15 24.7837 14.8 24.8671C14.6 24.9504 14.3833 25.0004 14.1667 25.0004C13.95 25.0004 13.7333 24.9504 13.5333 24.8671C13.3333 24.7837 13.15 24.6671 12.9833 24.5171C12.8333 24.3504 12.7167 24.1671 12.6333 23.9671C12.55 23.7671 12.5 23.5504 12.5 23.3337C12.5 23.1171 12.55 22.9004 12.6333 22.7004C12.7167 22.5004 12.8333 22.3171 12.9833 22.1504C13.15 22.0004 13.3333 21.8837 13.5333 21.8004C13.9333 21.6337 14.4 21.6337 14.8 21.8004C15 21.8837 15.1833 22.0004 15.35 22.1504C15.5 22.3171 15.6167 22.5004 15.7 22.7004C15.7833 22.9004 15.8333 23.1171 15.8333 23.3337C15.8333 23.5504 15.7833 23.7671 15.7 23.9671ZM21.1833 24.5171C21.0167 24.6671 20.8333 24.7837 20.6333 24.8671C20.4333 24.9504 20.2167 25.0004 20 25.0004C19.7833 25.0004 19.5667 24.9504 19.3667 24.8671C19.1667 24.7837 18.9833 24.6671 18.8167 24.5171C18.5167 24.2004 18.3333 23.7671 18.3333 23.3337C18.3333 22.9004 18.5167 22.4671 18.8167 22.1504C18.9833 22.0004 19.1667 21.8837 19.3667 21.8004C19.7667 21.6171 20.2333 21.6171 20.6333 21.8004C20.8333 21.8837 21.0167 22.0004 21.1833 22.1504C21.4833 22.4671 21.6667 22.9004 21.6667 23.3337C21.6667 23.7671 21.4833 24.2004 21.1833 24.5171Z" fill="white"/>
                             </svg>',
                'stat'    => $bookingWidgetStats['bookedStat']
            ],
            [
                'title'   => __('Completed Bookings', 'fluent-booking'),
                'period'  => 'completed',
                'number'  => $bookingWidgetNumbers['bookingCompleted'],
                'content' => $bookingWidgetStats['completedComparison'],
                'icon'    => '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 40 40" fill="none">
                                <path d="M27.9166 5.93398V3.33398C27.9166 2.65065 27.35 2.08398 26.6666 2.08398C25.9833 2.08398 25.4166 2.65065 25.4166 3.33398V5.83398H14.5833V3.33398C14.5833 2.65065 14.0166 2.08398 13.3333 2.08398C12.65 2.08398 12.0833 2.65065 12.0833 3.33398V5.93398C7.58331 6.35065 5.39998 9.03398 5.06664 13.0173C5.03331 13.5007 5.43331 13.9007 5.89998 13.9007H34.1C34.5833 13.9007 34.9833 13.484 34.9333 13.0173C34.6 9.03398 32.4166 6.35065 27.9166 5.93398Z" fill="white"/>
                                <path d="M31.6667 25C27.9833 25 25 27.9833 25 31.6667C25 32.9167 25.35 34.1 25.9667 35.1C27.1167 37.0333 29.2333 38.3333 31.6667 38.3333C34.1 38.3333 36.2167 37.0333 37.3667 35.1C37.9833 34.1 38.3333 32.9167 38.3333 31.6667C38.3333 27.9833 35.35 25 31.6667 25ZM35.1167 30.95L31.5667 34.2333C31.3333 34.45 31.0167 34.5667 30.7167 34.5667C30.4 34.5667 30.0833 34.45 29.8333 34.2L28.1833 32.55C27.7 32.0667 27.7 31.2667 28.1833 30.7833C28.6667 30.3 29.4667 30.3 29.95 30.7833L30.75 31.5833L33.4167 29.1167C33.9167 28.65 34.7167 28.6833 35.1833 29.1833C35.65 29.6833 35.6167 30.4667 35.1167 30.95Z" fill="white"/>
                                <path d="M33.3333 16.4004H6.66667C5.75 16.4004 5 17.1504 5 18.0671V28.3337C5 33.3337 7.5 36.6671 13.3333 36.6671H21.55C22.7 36.6671 23.5 35.5504 23.1333 34.4671C22.8 33.5004 22.5167 32.4337 22.5167 31.6671C22.5167 26.6171 26.6333 22.5004 31.6833 22.5004C32.1667 22.5004 32.65 22.5337 33.1167 22.6171C34.1167 22.7671 35.0167 21.9837 35.0167 20.9837V18.0837C35 17.1504 34.25 16.4004 33.3333 16.4004ZM15.35 30.3504C15.0333 30.6504 14.6 30.8337 14.1667 30.8337C13.7333 30.8337 13.3 30.6504 12.9833 30.3504C12.6833 30.0337 12.5 29.6004 12.5 29.1671C12.5 28.7337 12.6833 28.3004 12.9833 27.9837C13.15 27.8337 13.3167 27.7171 13.5333 27.6337C14.15 27.3671 14.8833 27.5171 15.35 27.9837C15.65 28.3004 15.8333 28.7337 15.8333 29.1671C15.8333 29.6004 15.65 30.0337 15.35 30.3504ZM15.35 24.5171C15.2667 24.5837 15.1833 24.6504 15.1 24.7171C15 24.7837 14.9 24.8337 14.8 24.8671C14.7 24.9171 14.6 24.9504 14.5 24.9671C14.3833 24.9837 14.2667 25.0004 14.1667 25.0004C13.7333 25.0004 13.3 24.8171 12.9833 24.5171C12.6833 24.2004 12.5 23.7671 12.5 23.3337C12.5 22.9004 12.6833 22.4671 12.9833 22.1504C13.3667 21.7671 13.95 21.5837 14.5 21.7004C14.6 21.7171 14.7 21.7504 14.8 21.8004C14.9 21.8337 15 21.8837 15.1 21.9504C15.1833 22.0171 15.2667 22.0837 15.35 22.1504C15.65 22.4671 15.8333 22.9004 15.8333 23.3337C15.8333 23.7671 15.65 24.2004 15.35 24.5171ZM21.1833 24.5171C20.8667 24.8171 20.4333 25.0004 20 25.0004C19.5667 25.0004 19.1333 24.8171 18.8167 24.5171C18.5167 24.2004 18.3333 23.7671 18.3333 23.3337C18.3333 22.9004 18.5167 22.4671 18.8167 22.1504C19.45 21.5337 20.5667 21.5337 21.1833 22.1504C21.4833 22.4671 21.6667 22.9004 21.6667 23.3337C21.6667 23.7671 21.4833 24.2004 21.1833 24.5171Z" fill="white"/>
                             </svg>',
                'stat'    => $bookingWidgetStats['completedStat']
            ]
        ];

        $totalPaymentWidget = apply_filters('fluent_booking/total_payment_widget', [], $paymentWidget);

        $widgets[] = $totalPaymentWidget ?: [
            'title'   => __('Cancelled Bookings', 'fluent-booking'),
            'period'  => 'cancelled',
            'number'  => $bookingWidgetNumbers['bookingCancelled'],
            'content' => $bookingWidgetStats['cancelledComparison'],
            'icon'    => '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 40 40" fill="none">
                            <path d="M27.9166 5.93398V3.33398C27.9166 2.65065 27.35 2.08398 26.6666 2.08398C25.9833 2.08398 25.4166 2.65065 25.4166 3.33398V5.83398H14.5833V3.33398C14.5833 2.65065 14.0166 2.08398 13.3333 2.08398C12.65 2.08398 12.0833 2.65065 12.0833 3.33398V5.93398C7.58331 6.35065 5.39998 9.03398 5.06664 13.0173C5.03331 13.5007 5.43331 13.9007 5.89998 13.9007H34.1C34.5833 13.9007 34.9833 13.484 34.9333 13.0173C34.6 9.03398 32.4166 6.35065 27.9166 5.93398Z" fill="white"/>
                            <path d="M33.3333 16.4004H6.66667C5.75 16.4004 5 17.1504 5 18.0671V28.3337C5 33.3337 7.5 36.6671 13.3333 36.6671H21.55C22.7 36.6671 23.5 35.5504 23.1333 34.4671C22.8 33.5004 22.5167 32.4337 22.5167 31.6671C22.5167 26.6171 26.6333 22.5004 31.6833 22.5004C32.1667 22.5004 32.65 22.5337 33.1167 22.6171C34.1167 22.7671 35.0167 21.9837 35.0167 20.9837V18.0837C35 17.1504 34.25 16.4004 33.3333 16.4004ZM15.35 29.5171C15.0333 29.8171 14.6 30.0004 14.1667 30.0004C13.95 30.0004 13.7333 29.9504 13.5333 29.8671C13.3333 29.7837 13.15 29.6671 12.9833 29.5171C12.6833 29.2004 12.5 28.7837 12.5 28.3337C12.5 28.1171 12.55 27.9004 12.6333 27.7004C12.7167 27.4837 12.8333 27.3171 12.9833 27.1504C13.15 27.0004 13.3333 26.8837 13.5333 26.8004C14.1333 26.5337 14.8833 26.6837 15.35 27.1504C15.5 27.3171 15.6167 27.4837 15.7 27.7004C15.7833 27.9004 15.8333 28.1171 15.8333 28.3337C15.8333 28.7837 15.65 29.2004 15.35 29.5171ZM15.35 23.6837C15.0333 23.9837 14.6 24.1671 14.1667 24.1671C13.95 24.1671 13.7333 24.1337 13.5333 24.0337C13.3333 23.9504 13.15 23.8337 12.9833 23.6837C12.6833 23.3671 12.5 22.9337 12.5 22.5004C12.5 22.2837 12.55 22.0671 12.6333 21.8671C12.7167 21.6671 12.8333 21.4837 12.9833 21.3171C13.15 21.1671 13.3333 21.0504 13.5333 20.9671C14.1333 20.7171 14.8833 20.8504 15.35 21.3171C15.5 21.4837 15.6167 21.6671 15.7 21.8671C15.7833 22.0671 15.8333 22.2837 15.8333 22.5004C15.8333 22.9337 15.65 23.3671 15.35 23.6837ZM21.5333 23.1337C21.45 23.3337 21.3333 23.5171 21.1833 23.6837C21.0167 23.8337 20.8333 23.9504 20.6333 24.0337C20.4333 24.1337 20.2167 24.1671 20 24.1671C19.5667 24.1671 19.1333 23.9837 18.8167 23.6837C18.6667 23.5171 18.55 23.3337 18.4667 23.1337C18.3833 22.9337 18.3333 22.7171 18.3333 22.5004C18.3333 22.0671 18.5167 21.6337 18.8167 21.3171C19.2833 20.8504 20.0167 20.7004 20.6333 20.9671C20.8333 21.0504 21.0167 21.1671 21.1833 21.3171C21.4833 21.6337 21.6667 22.0671 21.6667 22.5004C21.6667 22.7171 21.6333 22.9337 21.5333 23.1337Z" fill="white"/>
                            <path d="M31.6667 25C27.9833 25 25 27.9833 25 31.6667C25 35.35 27.9833 38.3333 31.6667 38.3333C35.35 38.3333 38.3333 35.35 38.3333 31.6667C38.3333 27.9833 35.35 25 31.6667 25ZM34.3333 34.4C34.0833 34.65 33.7667 34.7667 33.45 34.7667C33.1333 34.7667 32.8167 34.65 32.5667 34.4L31.6833 33.5167L30.7667 34.4333C30.5167 34.6833 30.2 34.8 29.8833 34.8C29.5667 34.8 29.25 34.6833 29 34.4333C28.5167 33.95 28.5167 33.15 29 32.6667L29.9167 31.75L29.0333 30.8667C28.55 30.3833 28.55 29.5833 29.0333 29.1C29.5167 28.6167 30.3167 28.6167 30.8 29.1L31.6833 30L32.5167 29.1667C33 28.6833 33.8 28.6833 34.2833 29.1667C34.7667 29.65 34.7667 30.45 34.2833 30.9333L33.45 31.7667L34.3333 32.65C34.8167 33.1333 34.8167 33.9167 34.3333 34.4Z" fill="white"/>
                            </svg>',
            'stat'    => $bookingWidgetStats['cancelledStat']
        ];

        $widgets[] = [
            'title'   => __('Total Guests', 'fluent-booking'),
            'number'  => $bookingWidgetNumbers['totalGuests'],
            'content' => $bookingWidgetStats['guestComparison'],
            'icon'    => '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 40 40" fill="none">
                            <path d="M15 3.33398C10.6334 3.33398 7.08337 6.88398 7.08337 11.2507C7.08337 15.534 10.4334 19.0007 14.8 19.1507C14.9334 19.134 15.0667 19.134 15.1667 19.1507C15.2 19.1507 15.2167 19.1507 15.25 19.1507C15.2667 19.1507 15.2667 19.1507 15.2834 19.1507C19.55 19.0007 22.9 15.534 22.9167 11.2507C22.9167 6.88398 19.3667 3.33398 15 3.33398Z" fill="white"/>
                            <path d="M23.4667 23.5828C18.8167 20.4828 11.2334 20.4828 6.55006 23.5828C4.43339 24.9995 3.26672 26.9161 3.26672 28.9661C3.26672 31.0161 4.43339 32.9161 6.53339 34.3161C8.86672 35.8828 11.9334 36.6661 15.0001 36.6661C18.0667 36.6661 21.1334 35.8828 23.4667 34.3161C25.5667 32.8995 26.7334 30.9995 26.7334 28.9328C26.7167 26.8828 25.5667 24.9828 23.4667 23.5828Z" fill="white"/>
                            <path d="M33.3167 12.2342C33.5834 15.4675 31.2834 18.3008 28.1001 18.6842C28.0834 18.6842 28.0834 18.6842 28.0667 18.6842H28.0167C27.9167 18.6842 27.8167 18.6841 27.7334 18.7175C26.1167 18.8008 24.6334 18.2841 23.5167 17.3341C25.2334 15.8008 26.2167 13.5008 26.0167 11.0008C25.9001 9.65082 25.4334 8.41748 24.7334 7.36748C25.3667 7.05082 26.1001 6.85082 26.8501 6.78415C30.1167 6.50082 33.0334 8.93415 33.3167 12.2342Z" fill="white"/>
                            <path d="M36.65 27.6494C36.5166 29.266 35.4833 30.666 33.75 31.616C32.0833 32.5327 29.9833 32.966 27.9 32.916C29.1 31.8327 29.8 30.4827 29.9333 29.0494C30.1 26.9827 29.1167 24.9994 27.15 23.416C26.0333 22.5327 24.7333 21.8327 23.3167 21.316C27 20.2494 31.6333 20.966 34.4833 23.266C36.0167 24.4994 36.8 26.0494 36.65 27.6494Z" fill="white"/>
                        </svg>',
            'stat'    => $bookingWidgetStats['guestStat']
        ];

        apply_filters('fluent_booking/dashboard_widgets', $widgets);

        return [
            'overview'      => $widgets,
            'latest_books'  => $this->getLatestBooks(),
            'next_meetings' => $this->getNextMeetings()
        ];
    }

    /**
     * @return array
     */
    public function getGraphReports(Request $request)
    {

        list($startDate, $endDate) = $request->get('date_range') ?: ['', ''];

        $period = $this->makeDatePeriod(
            $from = $this->makeFromDate($startDate),
            $to = $this->makeToDate($endDate),
            $frequency = $this->getFrequency($from, $to)
        );

        list($groupBy, $orderBy) = $this->getGroupAndOrder($frequency);

        // Define a function to fetch booking data based on status
        $fetchBookingsByStatus = function ($status) use ($period, $groupBy, $orderBy, $frequency, $from, $to) {

            if (!PermissionManager::userCanSeeAllBookings()) {

                return Booking::select($this->prepareSelect($frequency))
                    ->where('status', $status)
                    ->whereBetween('created_at', [$from->format('Y-m-d'), $to->format('Y-m-d')])
                    ->where('host_user_id', get_current_user_id())
                    ->groupBy($groupBy)
                    ->orderBy($orderBy, 'ASC')
                    ->get();
            }

            return Booking::select($this->prepareSelect($frequency))
                ->where('status', $status)
                ->whereBetween('created_at', [$from->format('Y-m-d'), $to->format('Y-m-d')])
                ->groupBy($groupBy)
                ->orderBy($orderBy, 'ASC')
                ->get();
        };

        // Fetch bookings for different statuses
        $totalBooked = $fetchBookingsByStatus('scheduled');
        $totalCompleted = $fetchBookingsByStatus('completed');
        $totalCancelled = $fetchBookingsByStatus('cancelled');

        return [
            'booked_stats'    => $this->getResult($period, $totalBooked),
            'completed_stats' => $this->getResult($period, $totalCompleted),
            'cancelled_stats' => $this->getResult($period, $totalCancelled)
        ];
    }

    private function getBookingWidgetStats($startTime, $endTime)
    {
        $startTimeStamp = strtotime($startTime);
        $endTimeStamp = strtotime($endTime);

        $differenceInDays = ($endTimeStamp - $startTimeStamp) / (60 * 60 * 24);

        $lastMonthStartTime = gmdate('Y-m-d H:i:s', strtotime("$startTime - $differenceInDays days")); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date

        $bookingStats = $this->getBookingStats($startTime, $endTime, $lastMonthStartTime, $startTime);

        $bookingStats['bookedComparison'] = $this->getComparisonMessage($bookingStats['bookedStat']);
        $bookingStats['completedComparison'] = $this->getComparisonMessage($bookingStats['completedStat']);
        $bookingStats['cancelledComparison'] = $this->getComparisonMessage($bookingStats['cancelledStat']);
        $bookingStats['guestComparison'] = $this->getComparisonMessage($bookingStats['guestStat']);

        return $bookingStats;
    }

    private function getBookingStats($currentMonthStart, $currentMonthEnd, $lastMonthStart, $lastMonthEnd)
    {
        // Get booking query by created_at and end_time
        $currentMonthBookings = Booking::whereBetween('created_at', [$currentMonthStart, $currentMonthEnd])->get();
        $lastMonthBookings = Booking::whereBetween('created_at', [$lastMonthStart, $lastMonthEnd])->get();

        $currentMonthStartBookings = Booking::whereBetween('end_time', [$currentMonthStart, $currentMonthEnd])->get();
        $lastMonthStartBookings = Booking::whereBetween('end_time', [$lastMonthStart, $lastMonthEnd])->get();

        // Calculate bookings and guests based on 'created_at'
        $totalBookedCurrentMonth = $currentMonthBookings->count();
        $totalBookedLastMonth = $lastMonthBookings->count();

        $totalGuestsCurrentMonth = $currentMonthBookings->pluck('email')->unique()->count();
        $totalGuestsLastMonth = $lastMonthBookings->pluck('email')->unique()->count();

        // Calculate completed and cancelled based on 'end_time'
        $bookingCompletedCurrentMonth = $currentMonthStartBookings->where('status', 'completed')->count();
        $bookingCompletedLastMonth = $lastMonthStartBookings->where('status', 'completed')->count();

        $bookingCancelledCurrentMonth = $lastMonthStartBookings->where('status', 'cancelled')->count();
        $bookingCancelledLastMonth = $currentMonthStartBookings->where('status', 'cancelled')->count();

        $bookingStats['bookedStat'] = $this->getPercentage($totalBookedCurrentMonth, $totalBookedLastMonth);
        $bookingStats['completedStat'] = $this->getPercentage($bookingCompletedCurrentMonth, $bookingCompletedLastMonth);
        $bookingStats['cancelledStat'] = $this->getPercentage($bookingCancelledCurrentMonth, $bookingCancelledLastMonth);
        $bookingStats['guestStat'] = $this->getPercentage($totalGuestsCurrentMonth, $totalGuestsLastMonth);

        return $bookingStats;
    }

    private function getPercentage($currentMonthTotal, $lastMonthTotal)
    {
        if ($lastMonthTotal > 0) {
            return round((($currentMonthTotal - $lastMonthTotal) / $lastMonthTotal) * 100, 2);
        } else if (!$lastMonthTotal) {
            return 100;
        }
        return 0;
    }

    private function getBookingWidgetNumbers($startTime, $endTime)
    {
        $statusQuery = Booking::whereBetween('end_time', [$startTime, $endTime])->get();
        $bookedQuery = Booking::whereBetween('created_at', [$startTime, $endTime])->get();

        $totalBooked = $bookedQuery->count();
        $totalGuests = $bookedQuery->pluck('email')->unique()->count();

        $bookingCompleted = $statusQuery->where('status', 'completed')->count();
        $bookingCancelled = $statusQuery->where('status', 'cancelled')->count();

        return [
            'totalBooked'      => $totalBooked,
            'totalGuests'      => $totalGuests,
            'bookingCompleted' => $bookingCompleted,
            'bookingCancelled' => $bookingCancelled
        ];
    }

    private function getAllBookingWidgetNumbers()
    {
        $permissionAccess = PermissionManager::userCan(['read_all_bookings', 'manage_all_bookings', 'read_other_calendars', 'manage_other_calendars']);

        if ($permissionAccess) {
            $totalBooked = Booking::count();
            $bookingCompleted = Booking::where('status', 'completed')->count();
            $bookingCancelled = Booking::where('status', 'cancelled')->count();
            $totalGuests = Booking::distinct()->count('email');
        } else {
            $totalBooked = Booking::where('host_user_id', get_current_user_id())->count();
            $bookingCompleted = Booking::where('status', 'completed')->where('host_user_id', get_current_user_id())->count();
            $bookingCancelled = Booking::where('status', 'cancelled')->where('host_user_id', get_current_user_id())->count();
            $totalGuests = Booking::distinct()->where('host_user_id', get_current_user_id())->count('email');
        }

        return [
            'totalBooked'      => $totalBooked,
            'totalGuests'      => $totalGuests,
            'bookingCompleted' => $bookingCompleted,
            'bookingCancelled' => $bookingCancelled
        ];
    }

    private function getComparisonMessage($change)
    {
        if ($change > 0) {
            return __('More than last month', 'fluent-booking');
        }
        if ($change < 0) {
            return __('Less than last month', 'fluent-booking');
        }

        return __('Same as last month', 'fluent-booking');

    }

    private function getPaymentWidgets($startTime, $endTime)
    {
        if (!defined('FLUENT_BOOKING_PRO_DIR_FILE')) {
            return [];
        }

        $stripSettings = get_option('fluent_booking_payment_settings_stripe');

        $isActive = Arr::get($stripSettings, 'is_active');

        if ($isActive == 'no') {
            return [];
        }

        $startTimeStamp = strtotime($startTime);
        $endTimeStamp = strtotime($endTime);

        $differenceInDays = ($endTimeStamp - $startTimeStamp) / (60 * 60 * 24);

        $lastMonthStartTime = gmdate('Y-m-d H:i:s', strtotime("$startTime - $differenceInDays days")); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date

        $current_user_email = null;

        $cantSeeTotal = PermissionManager::userCan(['read_all_bookings', 'manage_all_bookings', 'read_other_calendars', 'manage_other_calendars']);

        if (!$cantSeeTotal) {
            $current_user_email = wp_get_current_user()->user_email;
        }


        $currentMonthTotal = \FluentBookingPro\App\Models\Order::where('status', 'paid')
            ->whereBetween('created_at', [$startTime, $endTime])
            ->when($current_user_email, function ($query, $email) {
                return $query->whereHas('booking', function ($query) use ($email) {
                    $query->where('email', $email);
                });
            })
            ->selectRaw('SUM(total_amount / 100) as total')
            ->first()
            ->total;

        $lastMonthTotal = \FluentBookingPro\App\Models\Order::where('status', 'paid')
            ->whereBetween('created_at', [$lastMonthStartTime, $startTime])
            ->when($current_user_email, function ($query, $email) {
                return $query->whereHas('booking', function ($query) use ($email) {
                    $query->where('email', $email);
                });
            })
            ->selectRaw('SUM(total_amount / 100) as total')
            ->first()
            ->total;

        $paymentPercentage = $this->getPercentage($currentMonthTotal, $lastMonthTotal);

        $paymentComparison = $this->getComparisonMessage($paymentPercentage);

        $paymentStats['totalPayment'] = intval($currentMonthTotal);
        $paymentStats['paymentComparison'] = $paymentComparison;
        $paymentStats['paymentStat'] = $paymentPercentage;

        return $paymentStats;
    }

    public function getNextMeetings()
    {
        $bookingQuery = Booking::with(['slot'])
            ->where('status', 'scheduled')
            ->orderBy('start_time', 'ASC')
            ->upcoming();

        if (!PermissionManager::userCanSeeAllBookings()) {
            $bookingQuery->whereHas('calendar', function ($q) {
                $q->where('user_id', get_current_user_id());
            });
        }

        $nextMeetings = $bookingQuery->groupBy('group_id')->latest()->take(5)->get();

        foreach ($nextMeetings as $meeting) {
            if (!$meeting->slot) {
                $meeting->author = [
                    'name' => 'unknown'
                ];
                $meeting->slot = (object)[];
            } else {
                $meeting->author = $meeting->slot->getAuthorProfile(false);
            }

            $meeting->title = $meeting->getBookingTitle(true);

            if ($meeting->isMultiGuestBooking()) {
                $meeting->booked_count = Booking::where('group_id', $meeting->group_id)
                    ->whereIn('status', ['scheduled', 'completed'])->count();
            }
        }
        return $nextMeetings;
    }

    public function getLatestBooks()
    {
        $bookingQuery = Booking::whereIn('status', ['pending', 'scheduled', 'completed']);

        if (!PermissionManager::userCanSeeAllBookings()) {
            $bookingQuery->whereHas('calendar', function ($q) {
                $q->where('user_id', get_current_user_id());
            });
        }

        return $bookingQuery->latest()->take(5)->get();
    }

    public function getActivities()
    {
        $activityQuery = BookingActivity::query();

        if (!PermissionManager::userCanSeeAllBookings()) {
            $activityQuery->whereHas('booking.calendar', function ($q) {
                $q->where('user_id', get_current_user_id());
            });
        }

        $activities = $activityQuery->latest()->take(100)->get();

        return [
            'activities' => $activities
        ];
    }
}

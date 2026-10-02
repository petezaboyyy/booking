<?php
/**
 * @filesource modules/booking/views/view.php
 *
 * @copyright 2016 Goragod.com
 * @license https://www.kotchasan.com/license/
 *
 * @see https://www.kotchasan.com/
 */

namespace Booking\View;

use Kotchasan\Language;

/**
 * Show document details (modal)
 *
 * @author Goragod Wiriya <admin@goragod.com>
 *
 * @since 1.0
 */
class View extends \Kotchasan\KBase
{
    /**
     * Render booking details for modal/email style output.
     *
     * @param array $index
     * @param bool $email
     *
     * @return string
     */
    public static function render($index, $email = false)
    {
        if (!$email) {
            return self::renderModal($index);
        }
        $content = [];
        $escape = static function ($value) {
            return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        };
        $escapeMultiline = static function ($value) use ($escape) {
            return nl2br($escape($value));
        };

        if ($email) {
            $content[] = '<header>';
            $content[] = '<h4>{LNG_Reservation details} '.$escape($index['room_name']).'</h4>';
            $content[] = '</header>';
        }

        $content[] = '<table class="fullwidth">';
        // Absent for guests: the public view strips the requester's identity.
        if (!empty($index['member_name'])) {
            $content[] = '<tr><td class="item"><span class="icon-user">{LNG_Name}</span></td><td class="item"> : </td><td class="item">'.$escape($index['member_name']).'</td></tr>';
        }
        $roomText = $escape($index['room_name']);
        if (!empty($index['room_number'])) {
            $roomText .= ' ('.$escape($index['room_number']).')';
        }
        $content[] = '<tr><td class="item"><span class="icon-office">{LNG_Room}</span></td><td class="item"> : </td><td class="item">'.$roomText.'</td></tr>';
        if (!empty($index['room_building'])) {
            $content[] = '<tr><td class="item"><span class="icon-location">{LNG_Building}</span></td><td class="item"> : </td><td class="item">'.$escape($index['room_building']).'</td></tr>';
        }
        if (!empty($index['topic'])) {
            $content[] = '<tr><td class="item"><span class="icon-file">{LNG_Topic}</span></td><td class="item"> : </td><td class="item">'.$escapeMultiline($index['topic']).'</td></tr>';
        }
        if (trim((string) ($index['course_code'] ?? '')) !== '') {
            $content[] = '<tr><td class="item"><span class="icon-file">{LNG_Course code}</span></td><td class="item"> : </td><td class="item">'.$escape($index['course_code']).'</td></tr>';
        }
        if (!empty($index['comment'])) {
            $content[] = '<tr><td class="item"><span class="icon-comments">{LNG_Notes}</span></td><td class="item"> : </td><td class="item">'.$escapeMultiline($index['comment']).'</td></tr>';
        }
        $reservationText = !empty($index['reservation_text']) ? $index['reservation_text'] : $index['begin_text'].' - '.$index['end_text'];
        $content[] = '<tr><td class="item"><span class="icon-calendar">{LNG_Reservation period}</span></td><td class="item"> : </td><td class="item">'.$escape($reservationText).'</td></tr>';
        if (!empty($index['active_dates_text'])) {
            $content[] = '<tr><td class="item"><span class="icon-calendar">{LNG_Selected booking dates}</span></td><td class="item"> : </td><td class="item">'.$escape($index['active_dates_text']).'</td></tr>';
        }
        if (!empty($index['cancelled_dates_text'])) {
            $content[] = '<tr><td class="item"><span class="icon-ban">{LNG_Cancelled dates}</span></td><td class="item"> : </td><td class="item">'.$escape($index['cancelled_dates_text']).'</td></tr>';
        }
        $content[] = '<tr><td class="item"><span class="icon-group">{LNG_Attendees number}</span></td><td class="item"> : </td><td class="item">'.$escape($index['attendees']).'</td></tr>';
        $content[] = '<tr><td class="item"><span class="icon-star0">{LNG_Status}</span></td><td class="item"> : </td><td class="item">'.$escape($index['status_text']).'</td></tr>';
        if (!empty($index['reason'])) {
            $content[] = '<tr><td class="item"><span class="icon-file">{LNG_Reason}</span></td><td class="item"> : </td><td class="item">'.$escapeMultiline($index['reason']).'</td></tr>';
        }
        $content[] = '</table>';
        // Restore HTML
        return implode("\n", $content);
    }

    /**
     * Booking details for the in-app modal: label/value rows styled by
     * .roomly-booking-detail (css/dashboard.css), dates as chips, status as a pill.
     * Email keeps the plain table above, since mail clients ignore the stylesheet.
     *
     * @param array $index
     *
     * @return string
     */
    private static function renderModal(array $index): string
    {
        $escape = static function ($value) {
            return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        };
        $multiline = static function ($value) use ($escape) {
            return nl2br($escape($value));
        };
        $chips = static function (array $dates, string $class) use ($escape) {
            $items = array_map(static function ($date) use ($escape, $class) {
                return '<span class="roomly-detail-chip'.$class.'">'.$escape($date).'</span>';
            }, $dates);
            return '<div class="roomly-detail-chips">'.implode('', $items).'</div>';
        };

        $rows = [];
        $add = static function ($label, $html) use (&$rows) {
            $rows[] = '<div class="roomly-detail-row"><dt>{LNG_'.$label.'}</dt><dd>'.$html.'</dd></div>';
        };

        // Absent for guests: the public view strips the requester's identity.
        if (!empty($index['member_name'])) {
            $add('Name', $escape($index['member_name']));
        }
        $room = $escape($index['room_name']);
        if (!empty($index['room_number'])) {
            $room .= ' <span class="roomly-detail-muted">('.$escape($index['room_number']).')</span>';
        }
        $add('Room', $room);
        if (!empty($index['room_building'])) {
            $add('Building', $escape($index['room_building']));
        }
        if (!empty($index['topic'])) {
            $add('Topic', $multiline($index['topic']));
        }
        if (trim((string) ($index['course_code'] ?? '')) !== '') {
            $add('Course code', $escape($index['course_code']));
        }
        if (!empty($index['comment'])) {
            $add('Notes', $multiline($index['comment']));
        }
        $add('Reservation period', $escape(!empty($index['reservation_text'])
            ? $index['reservation_text']
            : $index['begin_text'].' - '.$index['end_text']));
        if (!empty($index['active_dates'])) {
            $add('Selected booking dates', $chips($index['active_dates'], ''));
        } elseif (!empty($index['active_dates_text'])) {
            $add('Selected booking dates', $escape($index['active_dates_text']));
        }
        if (!empty($index['cancelled_dates'])) {
            $add('Cancelled dates', $chips($index['cancelled_dates'], ' is-cancelled'));
        } elseif (!empty($index['cancelled_dates_text'])) {
            $add('Cancelled dates', $escape($index['cancelled_dates_text']));
        }
        $add('Attendees number', $escape($index['attendees']));
        $statusClass = [
            \Booking\Helper\Controller::STATUS_PENDING_REVIEW => ' is-pending',
            \Booking\Helper\Controller::STATUS_APPROVED => ' is-approved',
            \Booking\Helper\Controller::STATUS_REJECTED => ' is-rejected',
            \Booking\Helper\Controller::STATUS_CANCELLED_BY_REQUESTER => ' is-cancelled',
            \Booking\Helper\Controller::STATUS_CANCELLED_BY_OFFICER => ' is-cancelled',
            \Booking\Helper\Controller::STATUS_RETURNED_FOR_EDIT => ' is-pending'
        ][(int) ($index['status'] ?? -1)] ?? '';
        $add('Status', '<span class="roomly-detail-status'.$statusClass.'">'.$escape($index['status_text']).'</span>');
        // Absent for guests (stripped like the requester's name) and for bookings
        // approved automatically, which have no approving staff member.
        if (!empty($index['approver_name'])) {
            $add('Approver', $escape($index['approver_name']));
        }
        if (!empty($index['reason'])) {
            $add('Reason', $multiline($index['reason']));
        }

        $media = '';
        if (!empty($index['room_image_url'])) {
            $media = '<div class="roomly-detail-media"><img src="'.$escape($index['room_image_url']).'" alt="'.$escape($index['room_name']).'"></div>';
        }

        return '<div class="roomly-booking-detail">'.$media.'<dl class="roomly-detail-list">'.implode('', $rows).'</dl></div>';
    }

    /**
     * Build the shared modal action payload for booking details.
     *
     * @param array $index
     *
     * @return array
     */
    public static function buildModalAction(array $index): array
    {
        return [
            'type' => 'modal',
            'action' => 'show',
            'html' => Language::trans(static::render($index)),
            'title' => trim(Language::get('Details of').' '.$index['room_name'])
        ];
    }
}

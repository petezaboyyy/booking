<?php
/**
 * @filesource modules/booking/controllers/export.php
 */

namespace Booking\Export;

use Booking\Booking\Model as BookingModel;
use Booking\Helper\Controller as Helper;
use Gcms\Api as ApiController;
use Kotchasan\Http\Request;
use Kotchasan\Language;

/**
 * CSV export for an approved booking request.
 *
 * Reached through export.php?module=booking&typ=csv&id=<id>, which
 * Index\Export\Controller dispatches here after authenticating the request.
 * Every rule is re-checked below: the button may be hidden, but the URL is
 * still guessable.
 */
class Controller extends ApiController
{
    /**
     * Ceiling on how many requests one download may cover. A term of daily
     * bookings is already hundreds of rows; this stops a tick-everything
     * click turning into an unbounded query.
     */
    const MAX_REQUESTS = 200;

    /**
     * One row per approved date of one approved request.
     *
     * @param Request $request
     *
     * @return \Kotchasan\Http\Response|void Sends the file and exits on success
     */
    public function csv(Request $request)
    {
        try {
            ApiController::validateMethod($request, 'GET');

            // Index\Export\Controller already authenticated, but this class is
            // public: never rely on the caller for the identity.
            $login = $this->authenticateRequest($request);
            if (!$login) {
                return $this->errorResponse('Unauthorized', 401);
            }
            // Approvers are not enough — the export carries the requester's
            // email and the whole term's schedule.
            if (!ApiController::isAdmin($login)) {
                return $this->errorResponse('Permission required', 403);
            }

            // The approvals table sends the ticked rows as ids; the single-id
            // form is kept so one request can still be linked to directly.
            $ids = array_values(array_unique(array_filter(array_map(
                'intval',
                preg_split('/[\s,]+/', $request->get('ids')->toString(), -1, PREG_SPLIT_NO_EMPTY)
            ))));
            if (empty($ids)) {
                $single = $request->get('id')->toInt();
                if ($single > 0) {
                    $ids = [$single];
                }
            }
            if (empty($ids)) {
                return $this->errorResponse('No data available', 404);
            }
            if (count($ids) > self::MAX_REQUESTS) {
                return $this->errorResponse('Select fewer requests to export at once', 400);
            }

            // Status is read from the database, never from the URL. The query
            // returns nothing unless the request and at least one of its dates
            // are approved, so a pending, rejected or fully cancelled request
            // falls through to the error below instead of producing an empty
            // file.
            $rows = BookingModel::getApprovedExportRows($ids);
            if (empty($rows)) {
                return $this->errorResponse('This booking has no approved dates to export', 404);
            }

            $dayNames = Language::get('DATE_LONG', []);
            $datas = [];
            foreach ($rows as $row) {
                $begin = (string) $row->begin;
                $end = (string) $row->end;
                $department = (string) ($row->department ?? '');
                if ($department === '') {
                    $department = (string) ($row->member_department ?? '');
                }
                $datas[] = array_map([self::class, 'cell'], [
                    $row->id,
                    $row->member_name,
                    $row->member_email,
                    Helper::getDepartmentName($department) ?: $department,
                    $row->room_number,
                    $row->room_name,
                    $row->room_building,
                    trim((string) $row->course_code.' '.(string) $row->topic),
                    Helper::getUseLabel($row->use_id ?? ''),
                    substr($begin, 0, 10),
                    $dayNames[(int) date('w', strtotime($begin))] ?? '',
                    substr($begin, 11, 5),
                    substr($end, 11, 5),
                    $row->attendees,
                    Helper::formatAccessoryNames($row->accessories ?? ''),
                    $row->comment,
                    $row->created_at,
                    $row->approved_at
                ]);
            }

            $header = [
                Language::get('Request ID'),
                Language::get('Requester'),
                Language::get('Email'),
                Language::get('Department'),
                Language::get('Room No.'),
                Language::get('Room'),
                Language::get('Building'),
                Language::get('Topic'),
                Language::get('Use for'),
                Language::get('Date'),
                Language::get('Day'),
                Language::get('Begin time'),
                Language::get('End time'),
                Language::get('Attendees number'),
                Language::get('Accessories'),
                Language::get('Notes'),
                Language::get('Created'),
                Language::get('Approved at')
            ];

            \Index\Log\Model::add(
                count($ids) === 1 ? $ids[0] : 0,
                'booking',
                'Export',
                'Booking CSV exported: '.implode(', ', $ids),
                $login->id,
                null,
                ['reservation_ids' => $ids, 'approved_occurrences' => count($datas)]
            );

            $name = count($ids) === 1 ? 'booking-'.$ids[0] : 'booking-'.count($ids).'-requests';
            \Export\Export\Controller::csv($name.'-'.date('Ymd'), $header, $datas);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), $e->getCode() ?: 500, $e);
        }
    }

    /**
     * Make one value safe to put in a spreadsheet cell.
     *
     * Excel and Sheets treat a leading =, +, - or @ as a formula, so a topic
     * typed as "=cmd|..." would execute on open. The tab and carriage return
     * are dropped because fputcsv would otherwise split or pad the row, and
     * newlines inside a note become spaces so one booking stays one line.
     *
     * @param mixed $value
     *
     * @return string
     */
    protected static function cell($value): string
    {
        $value = str_replace(["\r\n", "\r", "\n", "\t"], ' ', (string) $value);
        $value = trim(preg_replace('/\s+/u', ' ', $value));
        if ($value !== '' && strpos("=+-@", $value[0]) !== false) {
            // A leading apostrophe is what spreadsheets read as "this is text".
            $value = "'".$value;
        }

        return $value;
    }
}

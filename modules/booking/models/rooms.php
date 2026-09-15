<?php
/**
 * @filesource modules/booking/models/rooms.php
 */

namespace Booking\Rooms;

use Booking\Helper\Controller as Helper;

class Model extends \Kotchasan\Model
{
    /**
     * Query data to send to DataTable.
     *
     * @param array $params
     *
     * @return \Kotchasan\QueryBuilder\QueryBuilderInterface
     */
    public static function toDataTable($params, $login = null)
    {
        $query = static::createQuery()
            ->select(
                'R.id',
                'R.name',
                'R.color',
                'R.detail',
                'R.is_active',
                'Building.value building',
                'RoomNumber.value number',
                'Seats.value seats'
            )
            ->from('rooms R')
            ->join('rooms_meta Building', [['Building.room_id', 'R.id'], ['Building.name', 'building']], 'LEFT')
            ->join('rooms_meta RoomNumber', [['RoomNumber.room_id', 'R.id'], ['RoomNumber.name', 'number']], 'LEFT')
            ->join('rooms_meta Seats', [['Seats.room_id', 'R.id'], ['Seats.name', 'seats']], 'LEFT');

        $roomScope = Helper::getBookingRoomScope($login);
        if ($roomScope !== null) {
            $query->where(['R.id', $roomScope]);
        }

        return $query;
    }
}

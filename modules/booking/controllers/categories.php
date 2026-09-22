<?php
/**
 * @filesource modules/booking/controllers/categories.php
 */

namespace Booking\Categories;

use Kotchasan\Http\Request;

class Controller extends \Index\Categories\Controller
{
    /** @var string */
    protected $modelClass = Model::class;

    /**
     * Supported category types and their labels.
     *
     * @var array
     */
    protected $categories = [
        'use' => '{LNG_Use for}',
        'accessories' => '{LNG_Accessories}'
    ];

    protected function prepareCategorySaveData(Request $request, array $item, $key, string $type): array
    {
        if ($type === 'use') {
            $values = $request->post('requires_course_code', [])->toInt();
            $item['requires_course_code'] = empty($values[$key]) ? 0 : 1;
        }

        return $item;
    }
}

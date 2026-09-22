<?php
/**
 * @filesource modules/booking/controllers/categories.php
 */

namespace Booking\Categories;

use Kotchasan\Http\Request;
use Kotchasan\Text;

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

            // Optional English name shown when the site language is English.
            // Left blank, the Thai `topic` is used everywhere - see
            // Helper::localizeCategoryOptionText() and Model::englishNames().
            $topicEn = $request->post('topic_en', [])->topic();
            $item['topic_en'] = isset($topicEn[$key]) ? Text::topic($topicEn[$key]) : '';
        }

        return $item;
    }
}

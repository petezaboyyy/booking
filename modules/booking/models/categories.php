<?php
/**
 * @filesource modules/booking/models/categories.php
 */

namespace Booking\Categories;

class Model extends \Index\Categories\Model
{
    public static function get($type)
    {
        $languages = \Index\Languages\Model::getLanguageColumns();
        $query = static::createQuery()
            ->select('category_id', 'topic', 'language', 'requires_course_code')
            ->from('category')
            ->where(['type', $type]);
        $data = [];
        foreach ($query->fetchAll() as $item) {
            if (!isset($data[$item->category_id])) {
                $data[$item->category_id] = [
                    'id' => $item->category_id,
                    'requires_course_code' => (int) $item->requires_course_code
                ];
                foreach ($languages as $lng) {
                    $data[$item->category_id][$lng] = $item->topic;
                }
            }
        }
        if (empty($data)) {
            $data[0] = ['id' => 1, 'requires_course_code' => 0];
            foreach ($languages as $lng) {
                $data[0][$lng] = '';
            }
        }

        return array_values($data);
    }

    public static function getColumns($multiLanguage, $type = null)
    {
        $columns = parent::getColumns($multiLanguage, $type);
        if ($type === 'use') {
            $columns[] = [
                'field' => 'requires_course_code',
                'label' => 'Require course code',
                'cellElement' => 'checkbox',
                'class' => 'category-course-code-toggle',
                'cellClass' => 'center'
            ];
        }

        return $columns;
    }

    public static function courseCodeRequirements(): array
    {
        $requirements = [];
        $rows = static::createQuery()
            ->select('category_id', 'requires_course_code')
            ->from('category')
            ->where(['type', 'use'])
            ->fetchAll();
        foreach ($rows as $row) {
            $requirements[(string) $row->category_id] = (int) $row->requires_course_code === 1;
        }

        return $requirements;
    }

    public static function requiresCourseCode($categoryId): bool
    {
        if ((string) $categoryId === '') {
            return false;
        }
        $requirements = static::courseCodeRequirements();

        return !empty($requirements[(string) $categoryId]);
    }
}

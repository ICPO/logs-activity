<?php

namespace Icpo\LogsActivity;


use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Логгер активности
 *
 * @docs https://suhar.teamly.ru/space/2c720188-40b6-4212-9de3-2855da8e0012/article/9fe6d348-c677-476c-af7a-d95395b236e9
 */
trait LogsActivity
{
    /**
     * Метод для интеграции с Eloquent, который перегружает ивент-классы
     *
     * @return void
     */
    public static function bootLogsActivity()
    {
        $logEvents = static::$logEvents ?? ['created', 'updated', 'deleted'];

        // Создание модели
        if (in_array('created', $logEvents)) {
            static::created(function ($model) {
                // При создании модели логируем только заполненные значения
                $props = array_filter(self::getLoggableProps($model), fn($value) => $value !== null && $value !== '');
                self::createLog('created', $model, $props);
            });
        }
        // Редактирование модели
        if (in_array('updated', $logEvents)) {
            static::updating(function (Model $model) {
                $oldModel = (new static())->setRawAttributes($model->getRawOriginal());
                $model->propsBeforeUpdate = self::getLoggableProps($oldModel);
            });
            static::updated(function (Model $model) {
                // При редактировании модели логируем только изменившиеся значения
                $props = array_diff_assoc(self::getLoggableProps($model), $model->propsBeforeUpdate);
                $oldProps = array_intersect_key($model->propsBeforeUpdate, $props);
                if ( ! empty($props) || ! empty($oldProps)) {
                    self::createLog('updated', $model, $props, $oldProps);
                }
            });
        }
        // Удаление модели
        if (in_array('deleted', $logEvents)) {
            static::deleting(function (Model $model) {
                $oldProps = array_filter(self::getLoggableProps($model), fn($value) => $value !== null && $value !== '');
                self::createLog('deleted', $model, null, $oldProps);
            });
        }
    }

    /**
     * @var array Добавляем в модели доп.атрибут, в котором будем хранить значения до фактического изменения
     */
    protected array $propsBeforeUpdate = [];

    /**
     * @return array Получить значения для всех заполняемых значений, включая meta->* как отдельные ключи
     */
    private static function getLoggableProps(Model $model): array
    {
        // Определяем набор параметров, которые нужно логировать, при этом отдельно мета-поле целиком не записываем
        $fillable = array_diff($model->getFillable(), ['meta']);
        if ($logOnly = self::getModelSetting($model, 'logOnly')) {
            $fillable = array_intersect($fillable, $logOnly);
        } elseif ($logExcept = self::getModelSetting($model, 'logExcept')) {
            $fillable = array_diff($fillable, $logExcept);
        }
        $result = [];
        foreach ($fillable as $prop) {
            if (preg_match('~^([a-zA-Z\d_]+)->([a-zA-Z\d_]+)$~', $prop, $m)) {
                $result[$prop] = data_get($model->{$m[1]}, $m[2]);
            } else {
                $result[$prop] = $model->{$prop};
            }
        }

        return $result;
    }

    /**
     * Получить значение, заданное в модели в виде атрибута или метода, возвращающего значение
     *
     * @param Model $model
     * @param string $settingName
     * @param mixed|null $default
     *
     * @return mixed
     */
    private static function getModelSetting(Model $model, string $settingName, mixed $default = null): mixed
    {
        if (method_exists($model, $settingName)) {
            return $model->{$settingName}();
        } elseif (property_exists($model, $settingName)) {
            return $model->{$settingName};
        }

        return $default;
    }

    /**
     * Получить набор полей, для которых необходимо скрыть значения
     *
     * @param Model $model
     *
     * @return void
     */
    private static function getHiddenProps(Model $model): array
    {
        $hiddenCasts = ['hashed', 'encrypted', 'encrypted:array', 'encrypted:collection', 'encrypted:object'];

        return collect($model->getCasts())
            ->filter(fn($cast, $prop) => in_array($cast, $hiddenCasts))->keys()->toArray();
    }

    /**
     * Скрыть значения, которые должны быть скрыты в модели
     *
     * @param Model $model
     * @param array $values
     * @param string $hideWith
     *
     * @return array
     */
    private static function secureHiddenProps(Model $model, array $values, string $hideWith = '***'): array
    {
        // Определяем типы данных, чтобы где-то более правильно сохранять значения
        $hiddenProps = self::getModelSetting($model, 'logHidden') ?? self::getHiddenProps($model);
        foreach ($hiddenProps as $prop) {
            if (isset($values[$prop])) {
                $values[$prop] = $hideWith;
            }
        }

        return $values;
    }

    private static function createLog(string $event, Model $model, ?array $props = null, ?array $oldProps = null)
    {
        DB::table('activity_log')->insert([
            // Что редактируем? Храним название модели без App\Models\ для уменьшения объема данных
            'subject_type' => strtolower(preg_replace('~^App\\\Models\\\~', '', get_class($model))),
            'subject_id' => $model->id,
            // Событие
            'event' => $event,
            // Автор события. Храним название модели без App\Model\ для уменьшения объема данных
            'causer_type' => auth()->hasUser() ? 'user' : null,
            'causer_id' => auth()->hasUser() ? auth()->user()->id : null,
            'props' => $props
                ? json_encode(self::secureHiddenProps($model, $props), JSON_UNESCAPED_UNICODE)
                : null,
            'old_props' => $oldProps
                ? json_encode(self::secureHiddenProps($model, $oldProps), JSON_UNESCAPED_UNICODE)
                : null,
            'created_at' => (string) now(),
        ]);
    }
}
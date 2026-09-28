<?php

namespace App\Model;

/**
 * The settings of a factory reset in the shape of the editor state, so the editor can mark every setting
 * that differs. Built from the configuration read after a reset (resources/factory_config.bin) and the group
 * control the device starts with.
 */
final class FactoryDefaults
{
    /** Values that are no preset content, or that the configuration does not carry. */
    private const LEFT_OUT = ['raw', 'footer', 'sub_extra', 'preset_name', 'preset_names', 'preset_stored', 'active_preset',
        'ip', 'device_id', 'working_status', 'tone_mode', 'tone_freq'];

    /**
     * @return array<string, mixed>
     */
    public static function fromFile(string $path): array
    {
        $config = is_file($path) ? file_get_contents($path) : false;
        if (false === $config) {
            throw new \RuntimeException(\sprintf('Cannot read the factory configuration %s.', $path));
        }
        $defaults = array_diff_key((new DeviceState($config))->toDevice()->toArray(), array_flip(self::LEFT_OUT));
        foreach ($defaults['inputs'] as $i => $input) {
            unset($defaults['inputs'][$i]['phantom']);
        }

        return $defaults;
    }
}

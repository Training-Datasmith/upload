<?php

declare (strict_types=1);
/**
 * @package    Fuel\Upload
 * @version    2.0
 * @author     Fuel Development Team
 * @license    MIT License
 * @copyright  2010-2025 Fuel Development Team
 * @link       http://fuelphp.com
 */
namespace Fuel\Upload\Providers;

use Fuel\Upload\Upload;
use League\Container\Service_Provider;
/**
 * Fuel ServiceProvider class for Upload
 */
class Fuel_Service_Provider extends Service_Provider
{
    /**
     * @var array
     */
    protected $provides = ['upload'];
    /**
     * {@inheritdoc}
     */
    public function register()
    {
        $this->get_container()->add('upload', function (?array $config = null) {
            return new Upload($config);
        });
    }
}
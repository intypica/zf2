<?php
/**
 * Zend Framework
 *
 * LICENSE
 *
 * This source file is subject to the new BSD license that is bundled
 * with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://framework.zend.com/license/new-bsd
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@zend.com so we can send you a copy immediately.
 *
 * @category   Zend
 * @package    Zend_PDF
 * @package    Zend_PDF_Internal
 * @copyright  Copyright (c) 2005-2010 Zend Technologies USA Inc. (http://www.zend.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 * @version    $Id$
 */

/**
 * @namespace
 */
namespace Zend\PDF\InternalType;
use Zend\PDF;

/**
 * PDF file 'numeric' element implementation
 *
 * @uses       \Zend\PDF\InternalType\AbstractTypeObject
 * @uses       \Zend\PDF\Exception
 * @category   Zend
 * @package    Zend_PDF
 * @package    Zend_PDF_Internal
 * @copyright  Copyright (c) 2005-2010 Zend Technologies USA Inc. (http://www.zend.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 */
class NumericObject extends AbstractTypeObject
{
    /**
     * Object value
     *
     * @var numeric
     */
    public $value;


    /**
     * Object constructor
     *
     * @param numeric $val
     * @throws \Zend\PDF\Exception
     */
    public function __construct($val)
    {
        if (!is_numeric($val)) {
            throw new PDF\Exception('Argument must be numeric');
        }

        $this->value = $val;
    }


    /**
     * Return type of the element.
     *
     * @return integer
     */
    public function getType()
    {
        return AbstractTypeObject::TYPE_NUMERIC;
    }


    /**
     * Return object as string
     *
     * @param Zend_PDF_Factory $factory
     * @return string
     */
    public function toString($factory = null)
    {
        if (is_integer($this->value)) {
            return (string)$this->value;
        }

        /**
         * PDF doesn't support exponental format.
         * Fixed point format must be used instead
         */
        $prec = 0; $v = $this->value;
        while (abs( floor($v) - $v ) > 1e-10) {
            $prec++; $v *= 10;
        }
        return sprintf("%.{$prec}F", $this->value);
    }
}

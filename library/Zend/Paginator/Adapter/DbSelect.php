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
 * @package    Zend_Paginator
 * @copyright  Copyright (c) 2005-2010 Zend Technologies USA Inc. (http://www.zend.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 * @version    $Id$
 */

/**
 * @namespace
 */
namespace Zend\Paginator\Adapter;
use Zend\DB\Select;
use Zend\DB;
use Zend\Paginator;

/**
 * @uses       \Zend\DB\DB
 * @uses       \Zend\DB\Expr
 * @uses       \Zend\DB\Select\Select
 * @uses       \Zend\Paginator\Adapter\AdapterInterface
 * @uses       \Zend\Paginator\Exception
 * @category   Zend
 * @package    Zend_Paginator
 * @copyright  Copyright (c) 2005-2010 Zend Technologies USA Inc. (http://www.zend.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 */
class DbSelect implements AdapterInterface
{
    /**
     * Name of the row count column
     *
     * @var string
     */
    const ROW_COUNT_COLUMN = 'zend_paginator_row_count';

    /**
     * The COUNT query
     *
     * @var \Zend\DB\Select\Select
     */
    protected $_countSelect = null;

    /**
     * Database query
     *
     * @var \Zend\DB\Select\Select
     */
    protected $_select = null;

    /**
     * Total item count
     *
     * @var integer
     */
    protected $_rowCount = null;

    /**
     * Constructor.
     *
     * @param \Zend\DB\Select\Select $select The select query
     */
    public function __construct(Select\Select $select)
    {
        $this->_select = $select;
    }

    /**
     * Sets the total row count, either directly or through a supplied
     * query.  Without setting this, {@link getPages()} selects the count
     * as a subquery (SELECT COUNT ... FROM (SELECT ...)).  While this
     * yields an accurate count even with queries containing clauses like
     * LIMIT, it can be slow in some circumstances.  For example, in MySQL,
     * subqueries are generally slow when using the InnoDB storage engine.
     * Users are therefore encouraged to profile their queries to find
     * the solution that best meets their needs.
     *
     * @param  \Zend\DB\Select\Select|integer $totalRowCount Total row count integer
     *                                               or query
     * @return \Zend\Paginator\Adapter\DbSelect $this
     * @throws \Zend\Paginator\Exception
     */
    public function setRowCount($rowCount)
    {
        if ($rowCount instanceof Select\Select) {
            $columns = $rowCount->getPart(Select\Select::COLUMNS);

            $countColumnPart = $columns[0][1];

            if ($countColumnPart instanceof DB\Expr) {
                $countColumnPart = $countColumnPart->__toString();
            }

            $rowCountColumn = $this->_select->getAdapter()->foldCase(self::ROW_COUNT_COLUMN);

            // The select query can contain only one column, which should be the row count column
            if (false === strpos($countColumnPart, $rowCountColumn)) {
                throw new Paginator\Exception('Row count column not found');
            }

            $result = $rowCount->query(DB\DB::FETCH_ASSOC)->fetch();

            $this->_rowCount = count($result) > 0 ? $result[$rowCountColumn] : 0;
        } else if (is_integer($rowCount)) {
            $this->_rowCount = $rowCount;
        } else {
            throw new Paginator\Exception('Invalid row count');
        }

        return $this;
    }

    /**
     * Returns an array of items for a page.
     *
     * @param  integer $offset Page offset
     * @param  integer $itemCountPerPage Number of items per page
     * @return array
     */
    public function getItems($offset, $itemCountPerPage)
    {
        $this->_select->limit($itemCountPerPage, $offset);

        return $this->_select->query()->fetchAll();
    }

    /**
     * Returns the total number of rows in the result set.
     *
     * @return integer
     */
    public function count()
    {
        if ($this->_rowCount === null) {
            $this->setRowCount(
                $this->getCountSelect()
            );
        }

        return $this->_rowCount;
    }

    /**
     * Get the COUNT select object for the provided query
     *
     * TODO: Have a look at queries that have both GROUP BY and DISTINCT specified.
     * In that use-case I'm expecting problems when either GROUP BY or DISTINCT
     * has one column.
     *
     * @return \Zend\DB\Select\Select
     */
    public function getCountSelect()
    {
        /**
         * We only need to generate a COUNT query once. It will not change for
         * this instance.
         */
        if ($this->_countSelect !== null) {
            return $this->_countSelect;
        }

        $rowCount = clone $this->_select;
        $rowCount->__toString(); // Workaround for ZF-3719 and related

        $db = $rowCount->getAdapter();

        $countColumn = $db->quoteIdentifier($db->foldCase(self::ROW_COUNT_COLUMN));
        $countPart   = 'COUNT(1) AS ';
        $groupPart   = null;
        $unionParts  = $rowCount->getPart(Select\Select::UNION);

        /**
         * If we're dealing with a UNION query, execute the UNION as a subquery
         * to the COUNT query.
         */
        if (!empty($unionParts)) {
            $expression = new DB\Expr($countPart . $countColumn);

            $rowCount = $db->select()->from($rowCount, $expression);
        } else {
            $columnParts = $rowCount->getPart(Select\Select::COLUMNS);
            $groupParts  = $rowCount->getPart(Select\Select::GROUP);
            $havingParts = $rowCount->getPart(Select\Select::HAVING);
            $isDistinct  = $rowCount->getPart(Select\Select::DISTINCT);

            /**
             * If there is more than one column AND it's a DISTINCT query, more
             * than one group, or if the query has a HAVING clause, then take
             * the original query and use it as a subquery os the COUNT query.
             */
            if (($isDistinct && count($columnParts) > 1) || count($groupParts) > 1 || !empty($havingParts)) {
                $rowCount = $db->select()->from($this->_select);
            } else if ($isDistinct) {
                $part = $columnParts[0];

                if ($part[1] !== Select\Select::SQL_WILDCARD && !($part[1] instanceof DB\Expr)) {
                    $column = $db->quoteIdentifier($part[1], true);

                    if (!empty($part[0])) {
                        $column = $db->quoteIdentifier($part[0], true) . '.' . $column;
                    }

                    $groupPart = $column;
                }
            } else if (!empty($groupParts) && $groupParts[0] !== Select\Select::SQL_WILDCARD &&
                       !($groupParts[0] instanceof DB\Expr)) {
                $groupPart = $db->quoteIdentifier($groupParts[0], true);
            }

            /**
             * If the original query had a GROUP BY or a DISTINCT part and only
             * one column was specified, create a COUNT(DISTINCT ) query instead
             * of a regular COUNT query.
             */
            if (!empty($groupPart)) {
                $countPart = 'COUNT(DISTINCT ' . $groupPart . ') AS ';
            }

            /**
             * Create the COUNT part of the query
             */
            $expression = new DB\Expr($countPart . $countColumn);

            $rowCount->reset(Select\Select::COLUMNS)
                     ->reset(Select\Select::ORDER)
                     ->reset(Select\Select::LIMIT_OFFSET)
                     ->reset(Select\Select::GROUP)
                     ->reset(Select\Select::DISTINCT)
                     ->reset(Select\Select::HAVING)
                     ->columns($expression);
        }

        $this->_countSelect = $rowCount;

        return $rowCount;
    }
}

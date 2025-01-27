<?php

namespace ToolboxBundle\Document\Areabrick\Anchor;

use Symfony\Component\HttpFoundation\Response;
use ToolboxBundle\Document\Areabrick\AbstractAreabrick;
use Pimcore\Model\Document\Editable\Area\Info;

class Anchor extends AbstractAreabrick
{
    /**
     * {@inheritdoc}
     */
    public function action(Info $info): ?Response
    {
        return parent::action($info);
    }

    /**
     * @return string
     */
    public function getName()
    {
        return 'Anchor';
    }

    /**
     * @return string
     */
    public function getDescription()
    {
        return 'Toolbox Anchor';
    }
}

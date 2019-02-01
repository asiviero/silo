<?php

namespace Silo\Inventory\Model;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;
use Silo\Inventory\Collection\BatchCollection;

/**
 * Represent a movement of something from a Location to another Location.
 * Something can either be a Location, or a Batch set, but not both (could
 * be possible, but let's make it simple for futur generations).
 *
 * Operations can either be Pending, Cancelled or Executed. You can also rollback
 * an Executed Operation.
 *
 * @ORM\Table(name="operation")
 * @ORM\Entity(repositoryClass="Silo\Inventory\Repository\OperationRepository")
 */
class Operation implements MarshallableInterface
{
    /**
     * @var int
     *
     * @ORM\Column(name="operation_id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="IDENTITY")
     */
    private $id;

    /**
     * @var User Who requested this Operation
     * @ORM\ManyToOne(targetEntity="User")
     * @ORM\JoinColumn(name="requested_by", referencedColumnName="user_id")
     */
    private $requestedBy;

    /**
     * @var \DateTime When requested this Operation has been
     * @ORM\Column(name="requested_at", type="datetimetz")
     */
    private $requestedAt;

    /**
     * @var User Who did this Operation
     * @ORM\ManyToOne(targetEntity="User")
     * @ORM\JoinColumn(name="done_by", referencedColumnName="user_id", nullable=true)
     */
    private $doneBy;

    /**
     * @var \DateTime When requested this Operation has been
     * @ORM\Column(name="done_at", type="datetimetz", nullable=true)
     */
    private $doneAt;

    /**
     * @var User Who cancelled this Operation
     * @ORM\ManyToOne(targetEntity="User")
     * @ORM\JoinColumn(name="cancelled_by", referencedColumnName="user_id", nullable=true)
     */
    private $cancelledBy;

    /**
     * @var \DateTime When requested this Operation has been (Yoda style comment)
     * @ORM\Column(name="cancelled_at", type="datetimetz", nullable=true)
     */
    private $cancelledAt;

    /**
     * @var Location If null, this is a creation of something
     * @ORM\ManyToOne(targetEntity="Location")
     * @ORM\JoinColumn(name="source", referencedColumnName="location_id", nullable=true)
     */
    private $source;

    /**
     * @var Location If null, this is a removal of something
     * @ORM\ManyToOne(targetEntity="Location")
     * @ORM\JoinColumn(name="target", referencedColumnName="location_id", nullable=true)
     */
    private $target;

    /**
     * @var Location If set, this is a Location movement, or else this is a product movement
     * @ORM\ManyToOne(targetEntity="Location", cascade={"persist"})
     * @ORM\JoinColumn(name="location", referencedColumnName="location_id", nullable=true)
     */
    private $location;

    /**
     * @var ArrayCollection
     * @ORM\OneToMany(targetEntity="Batch", mappedBy="operation", cascade={"persist"})
     */
    private $batches;

    /**
     * @var OperationType Categorizes this operation
     * @ORM\ManyToOne(targetEntity="OperationType")
     * @ORM\JoinColumn(name="type", referencedColumnName="operation_type_id", nullable=true)
     */
    private $operationType;

    /**
     * @var Operation If present, then current operation has been rollbacked by rollback operation
     * @ORM\OneToOne(targetEntity="Operation")
     * @ORM\JoinColumn(name="rollback", referencedColumnName="operation_id", nullable=true)
     */
    private $rollbackOperation;

    /**
     * @var bool If this operation is a rollbacking one (aka cancel the rollbacked)
     * @ORM\Column(name="rollback_count", type="integer", nullable=false)
     */
    private $rollbackCount = 0;

    /**
     * @ORM\ManyToMany(targetEntity="OperationSet", mappedBy="operations")
     */
    private $operationSets;

    /**
     * @param User $requestedBy
     * @param $source
     * @param $target
     * @param $content
     *
     * @todo check $content, if ArrayCollection, is not persisted yet, to prevent Batch reuse.
     */
    public function __construct(
        User $requestedBy,
        $source,
        $target,
        $content
    ) {
        if (!$source instanceof Location && !is_null($source)) {
            throw new \LogicException('Source should be either Location or null');
        }
        if (!$target instanceof Location && !is_null($target)) {
            throw new \LogicException('Target should be either Location or null');
        }
        if (is_null($source) && is_null($target)) {
            throw new \LogicException('A source or a target should at least be specified');
        }
        if ($source && $target && $source->getCode() == $target->getCode()) {
            throw new \LogicException('Source and target should be different');
        }
        if (!$content instanceof Location && !$content instanceof ArrayCollection) {
            throw new \LogicException('Content should be either Location or ArrayCollection');
        }

        $this->requestedBy = $requestedBy;
        $this->source = $source;
        $this->target = $target;

        $this->requestedAt = new \DateTime();

        if ($content instanceof Location) {
            $this->location = $content;
        } else {
            $this->batches = $content;
            $that = $this;
            $ref = $this->batches->toArray();
            array_walk($ref, function (Batch $batch) use ($that) {
                $batch->setOperation($that);
            });
        }

        $this->operationSets = new \Doctrine\Common\Collections\ArrayCollection();
    }

    /**
     * Perform $this and apply changes on related Locations. Will mark $this as
     * done after flush.
     *
     * @param User $doneBy
     * @param BatchCollection $overrideBatches Batches that will replace current ones
     */
    public function execute(User $doneBy, BatchCollection $override = null)
    {
        if ($this->doneAt) {
            throw new \LogicException("Cannot execute $this, it has already been executed");
        }
        if ($this->cancelledAt) {
            throw new \LogicException("Cannot execute $this, it has been cancelled");
        }

        if ($override) {
            foreach ($this->batches->toArray() as $batch) {
                $batch->detach();
            }
            $this->batches->clear();
            foreach ($override->toArray() as $batch) {
                $this->batches->add($batch);
                $batch->setOperation($this);
            }
        }

        if ($location = $this->location) {
            $this->location->apply($this);
        } else {
            if (!is_null($this->source)) {
                $this->source->apply($this);
            }
            if (!is_null($this->target)) {
                $this->target->apply($this);
            }
        }

        $this->doneBy = $doneBy;
        $this->doneAt = new \DateTime();
    }

    public function cancel(User $cancelledBy)
    {
        if ($this->doneAt) {
            throw new \LogicException("Cannot cancel $this, it has been executed");
        }
        if ($this->cancelledAt) {
            throw new \LogicException("Cannot cancel $this, it has already been cancelled");
        }

        $this->cancelledBy = $cancelledBy;
        $this->cancelledAt = new \DateTime();
    }

    /**
     * @param User $rollbackUser
     *
     * @return Operation rollbacking operation. Execute it to make it happen.
     */
    public function createRollback(User $rollbackUser)
    {
        // not rollbacked by a done operation
        if ($this->rollbackOperation && $this->rollbackOperation->doneAt) {
            throw new \Exception("$this has already been rollbacked");
        }
        // has to be done to be rollbacked
        if (!$this->doneAt) {
            throw new \Exception("Cannot rollback $this, it is still pending");
        }

        if (!$this->location && $this->getBatches()->isEmpty()) {
            throw new \Exception("Cannot rollback $this, it is empty");
        }

        // @todo evaluate rollbacking with the same Batch instead of copying it
        $rollbackingOperation = new Operation(
            $rollbackUser,
            $this->target,
            $this->source,
            $this->location ?: BatchCollection::fromCollection($this->batches)->copy()
        );

        // Rollback tracks if the rollback chain is canceling or restablishing the first operation
        // first op: rollback_count=0 rollback=id
        // secon op: rollback_count=1 rollback=id
        // third op: rollback_count=2 rollback=null ...
        // So if rollback_count is even and rollback null, then the action counts
        // if rollback_count is odd and rollback is null, then the action does not count

        $rollbackingOperation->rollbackCount = $this->rollbackCount + 1;
        $this->rollbackOperation = $rollbackingOperation;

        return $rollbackingOperation;
    }

    /**
     * Cancel the current Operation, and return a pending replacement Operation
     * @param User $replaceUser
     * @param $replaceContent
     * @return Operation
     * @throws \Exception
     */
    public function createReplace(User $replaceUser, $replaceContent)
    {
        // has to be pending to be replaced
        if ($this->doneAt) {
            throw new \Exception("Cannot replace $this, it is done already");
        }
        if ($this->cancelledAt) {
            throw new \Exception("Cannot replace $this, it is cancelled already");
        }

        $replacingOperation = new Operation(
            $replaceUser,
            $this->target,
            $this->source,
            $replaceContent
        );
        $replacingOperation->operationType = $this->operationType;

        $this->replaceOperation = $replacingOperation;
        $this->cancel($replaceUser);

        return $replacingOperation;
    }

    /**
     * @return Location
     */
    public function getSource()
    {
        return $this->source;
    }

    /**
     * @return Location
     */
    public function getTarget()
    {
        return $this->target;
    }

    /**
     * @return Location
     */
    public function getLocation()
    {
        return $this->location;
    }

    public function isLocationOperation()
    {
        return !empty($this->location);
    }

    public function __toString()
    {
        return sprintf(
            'Operation:%s:%s:%s',
            $this->id,
            $this->source ? $this->source->getCode() : null,
            $this->target ? $this->target->getCode() : null
        );
    }

    /**
     * @return BatchCollection Copy of the contained Batches
     */
    public function getBatches()
    {
        if ($this->batches) {
            return BatchCollection::fromCollection($this->batches)->copy();
        } else {
            return new BatchCollection();
        }
    }

    /**
     * @param OperationType $type
     */
    public function setType(OperationType $type)
    {
        $this->operationType = $type;

        return $this;
    }

    /**
     * @return null|string Type of this operation.
     */
    public function getType()
    {
        if ($this->operationType) {
            return $this->operationType->getName();
        }

        return null;
    }

    public function getStatus()
    {
        return new OperationStatus($this);
    }

    /**
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @return Operation
     */
    public function getRollbackOperation()
    {
        return $this->rollbackOperation;
    }

    public function isRollbackPart()
    {
        // Rollback tracks if the rollback chain is canceling or restablishing the first operation
        // first op: rollback_count=0 rollback=id
        // secon op: rollback_count=1 rollback=id
        // third op: rollback_count=2 rollback=null ...
        // So if rollback_count is even and rollback null, then the action counts
        // if rollback_count is odd and rollback is null, then the action does not count

        return $this->rollbackOperation || (!$this->rollbackOperation && ($this->rollbackCount % 2 === 1));
    }

    public function addOperationSet(OperationSet $set)
    {
        return $this->operationSets->add($set);
    }

    public function removeOperationSet(OperationSet $set)
    {
        return $this->operationSets->removeElement($set);
    }

    /**
     * @return OperationSet[]
     * @todo do not return an array please
     */
    public function getOperationSets()
    {
        return $this->operationSets->toArray();
    }

    public function marshall()
    {
        return [
            'id' => $this->getId(),
            'source' => $this->getSource() ? $this->getSource()->getCode() : null,
            'target' => $this->getTarget() ? $this->getTarget()->getCode() : null,
            'type' => $this->getType(),
            'status' => $this->getStatus()->toArray(),
            'location' => $this->getLocation() ? $this->getLocation()->getCode() : null,
            'contexts' => array_map(function (OperationSet $context) {
                return $context->marshall();
            }, $this->getOperationSets()),
            'batches' => array_map(function($batch){return $batch->marshall();}, $this->getBatches()->toArray()),
            'isRollbackPart' => $this->isRollbackPart()
        ];
    }
}

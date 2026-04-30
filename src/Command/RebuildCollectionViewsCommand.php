<?php

namespace App\Command;

use App\Client\AlteredCoreClient;
use App\Entity\CollectionCard;
use App\Entity\CollectionCardView;
use App\Repository\CollectionCardRepository;
use App\Repository\CollectionCardViewRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'collection:rebuild-views',
    description: 'Rebuild collection_card_view from write model + altered-core metadata',
)]
class RebuildCollectionViewsCommand extends Command
{
    private const BATCH_SIZE = 200;
    private const FLUSH_SIZE = 50;

    public function __construct(
        private readonly CollectionCardRepository     $cardRepository,
        private readonly CollectionCardViewRepository $viewRepository,
        private readonly AlteredCoreClient            $alteredCoreClient,
        private readonly EntityManagerInterface       $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('locale', 'l', InputOption::VALUE_OPTIONAL, 'Locale for card name/imagePath', 'fr')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be done without persisting');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $locale  = $input->getOption('locale');
        $dryRun  = $input->getOption('dry-run');

        if ($dryRun) {
            $io->note('Dry-run mode — no changes will be persisted.');
        }

        /** @var CollectionCard[] $allCards */
        $allCards = $this->cardRepository->findAll();

        if (empty($allCards)) {
            $io->success('No collection cards found.');
            return Command::SUCCESS;
        }

        // Group by user so we can batch per-user and keep user reference
        $byUser = [];
        foreach ($allCards as $card) {
            $byUser[(string) $card->getUser()->getId()][] = $card;
        }

        $io->writeln(sprintf(
            'Found <info>%d</info> cards across <info>%d</info> user(s).',
            count($allCards),
            count($byUser),
        ));

        $progress = new ProgressBar($output, count($allCards));
        $progress->start();

        $updated = 0;
        $created = 0;
        $errors  = [];
        $pending = 0;

        foreach ($byUser as $userId => $cards) {
            // Split into chunks of 200 (AlteredCoreClient limit)
            foreach (array_chunk($cards, self::BATCH_SIZE) as $chunk) {
                $references = array_map(fn(CollectionCard $c) => $c->getCardReference(), $chunk);

                try {
                    $apiData = $this->alteredCoreClient->getCardsByReferences($references, $locale);
                } catch (\Throwable $e) {
                    foreach ($chunk as $card) {
                        $errors[] = sprintf('%s: %s', $card->getCardReference(), $e->getMessage());
                    }
                    $progress->advance(count($chunk));
                    continue;
                }

                foreach ($chunk as $card) {
                    $ref     = $card->getCardReference();
                    $data    = $apiData[$ref] ?? null;

                    if ($data === null) {
                        $errors[] = sprintf('%s: not found in altered-core', $ref);
                        $progress->advance();
                        continue;
                    }

                    $view = $this->viewRepository->findOneBy(['collectionCard' => $card]);
                    $isNew = $view === null;

                    if ($isNew) {
                        $view = new CollectionCardView();
                        $view->setCollectionCard($card);
                        $view->setUser($card->getUser());
                        $view->setCardReference($ref);
                        $view->setQuantity($card->getQuantity());
                        $view->setIsFoil($card->isFoil());
                        $created++;
                    } else {
                        $updated++;
                    }

                    $view->fillFromApiData($data, $locale);

                    if (!$dryRun) {
                        $this->em->persist($view);
                        $flushedViews[] = $view;
                        $pending++;

                        if ($pending >= self::FLUSH_SIZE) {
                            $this->em->flush();
                            foreach ($flushedViews as $flushed) {
                                $this->em->detach($flushed);
                            }
                            $flushedViews = [];
                            $pending = 0;
                        }
                    }

                    $progress->advance();
                }
            }
        }

        if (!$dryRun && $pending > 0) {
            $this->em->flush();
        }

        $progress->finish();
        $output->writeln('');

        $io->success(sprintf('Done — %d updated, %d created.', $updated, $created));

        if (!empty($errors)) {
            $io->warning(sprintf('%d card(s) could not be refreshed:', count($errors)));
            $io->listing($errors);
        }

        return empty($errors) ? Command::SUCCESS : Command::FAILURE;
    }
}

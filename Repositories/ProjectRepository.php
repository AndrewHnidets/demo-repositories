<?php

namespace App\Repositories;

use App\Entity\Project;
use App\Entity\ProjectPhoto;
use App\Events\ProjectCreatedEvent;
use App\Events\ProjectUpdatedEvent;
use App\Services\ImageSaverService;
use App\Services\LocalizationService;
use App\Services\LocationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use function foo\func;

class ProjectRepository
{
    private $partnerRepository;
    private $vacancyRepository;
    private $imageSaverService;
    private $locationService;
    private $localizationService;

    public function __construct(PartnerRepository $partnerRepository, VacancyRepository $vacancyRepository, ImageSaverService $imageSaverService, LocationService $locationService, LocalizationService $localizationService)
    {
        $this->partnerRepository = $partnerRepository;
        $this->vacancyRepository = $vacancyRepository;
        $this->imageSaverService = $imageSaverService;
        $this->locationService = $locationService;
        $this->localizationService = $localizationService;
    }

    public function createOrUpdate($data, $userId, $projectId = null)
    {
        return DB::transaction(function () use ($data, $userId, $projectId) {
            $formedData = $this->formData($data, $userId);

            if (!is_null($projectId)) {
                $project = Project::find($projectId);
                unset($formedData['slug']);

                $project->update($formedData);

                if ($project->isPublished())
                    event(new ProjectUpdatedEvent($project));
            } else {
                $project = Project::create($formedData);

                if ($project->isPublished())
                    event(new ProjectCreatedEvent($project));
            }


            $this->saveProjectAreas($data, $project);
            $this->saveLangFields($data, $project);
            $this->savePhotos($data, $project);
            $this->savePartnersAndVacancies($data, $project->id);
            $this->forceFillGoalIfHaveVacancies($project);
        });
    }

    /**
     * Create a slug.
     *
     * @param string $name
     * @return string
     */
    public function makeSlug($name)
    {
        $slug = Str::slug($name);

        $count = Project::withTrashed()->whereRaw("slug RLIKE '^{$slug}(-[0-9]+)?$'")->count() + 1;

        return $count ? "{$slug}-{$count}" : $slug;
    }

    public function saveLangFields($data, $project)
    {
        $fields = ['name', 'small_description', 'description'];

        $this->localizationService->saveLangFields($fields, $data, $project);
    }

    public function getExistingLang($data)
    {
        return $data['uk'] ?: $data['ru'] ?: $data['en'];
    }

    public function paginateProjectsWithAllRelation($userId = null, $params = null, $user = null)
    {
        return Project
            ::when(isset($userId) && !is_null($userId), function ($query) use ($userId) {
                return $query->where('user_id', $userId);
            }, function ($query) {
                return $query->published();
            })
            ->whereFilterInternational($params)
            ->whereFilterCity($params)
            ->whereFilterStatus($params)
            ->whereFilterProjectArea($params)
            ->whereFilterBudget($params)
            ->whereFilterGoal($params)
            ->whereFilterProjectRole($params)
            ->whereFilterTimeInRelease($params)
            ->whereFilterPriceRange($params)
            ->whereFilterSearch($params)
            ->whereOrder($params)
            ->whereSpecialistOrInvestorRooms($user)
            ->withAllRelation()
            ->whereHas('user', function ($query) {
                return $query->whereNull('deleted_at');
            })
            ->paginate(Project::PAGINATE_COUNT);
    }

    public function getPublishedBySlug($slug, $user)
    {
        return Project
            ::where('slug', $slug)
            ->withAllRelation()
            ->withUserChatRoom($user)
            ->whereHas('user', function ($query) {
                return $query->whereNull('deleted_at');
            })
            ->withTrashed()
            ->firstOrFail();
    }

    public function getUsersBySlug($slug, $userId)
    {
        return Project::where('slug', $slug)
            ->where('user_id', $userId)
            ->withAllRelation()
            ->firstOrFail();
    }

    private function formData($data, $userId)
    {
        $formedData = [
            'name' => $data['name']['uk'],
            'site' => isset($data['site']) ? $data['site'] : '',
            'goal' => isset($data['goal']) ? implode($data['goal'], ',') : '',
            'in_work' => isset($data['in_work']) ? $data['in_work'] : '',
            'small_description' => $data['small_description']['uk'],
            'status' => isset($data['status']) ? $data['status'] : '',
            'description' => $data['description']['uk'],
            'budget' => $data['budget'],
            'time_in_release' => $data['time_in_release'],
            'receive_messages' => isset($data['receive_messages']) ? $data['receive_messages'] : 0,
            'is_published' => isset($data['is_published']) ? $data['is_published'] : 0,
            'slug' => $this->makeSlug($this->getExistingLang($data['name'])),
            'user_id' => $userId,
            'full_address' => $data['autocomplete_search']
        ];

        $city = $this->locationService->createCityWithRelations($data);
        if (isset($data['city'])) {
            $formedData['city_id'] = $city->id;
        }

        return $formedData;
    }

    private function savePartnersAndVacancies($data, $projectId)
    {
        if ($data['partner']['role'][0] || count($data['partner']['id']) > 1)
            $this->partnerRepository->createOrUpdate($data, $projectId);
        else
            $this->partnerRepository->removePartners($projectId);

        if ($data['vacancy']['uk']['name'][0] || $data['vacancy']['ru']['name'][0] || $data['vacancy']['en']['name'][0] || count($data['vacancy']['id']) > 1)
            $this->vacancyRepository->createOrUpdate($data, $projectId);
        else
            $this->vacancyRepository->removeVacancies($projectId);
    }

    private function savePhotos($data, $project)
    {
        if (isset($data['photo']))
            foreach ($data['photo'] as $photo)
                $project->photos()->create([
                    'image' => $this->imageSaverService->store($photo, ProjectPhoto::IMAGE_PATH)
                ]);
    }

    private function saveProjectAreas($data, $project)
    {
        if (isset($data['project_area']))
            $project->areas()->sync($data['project_area']);
    }

    private function forceFillGoalIfHaveVacancies($project)
    {
        if ($project->vacancies->count())
            $project->addGoal('3');
        else
            $project->removeGoal('3');
    }

    public function removeFile($projectId, $photoId)
    {
        return DB::transaction(function () use ($projectId, $photoId) {
            $photo = ProjectPhoto::where('project_id', $projectId)->find($photoId);
            $this->cleanUpFile($photo);
        });
    }

    public function cleanUpFile($photo)
    {
        if (!$photo)
            return false;
        $this->imageSaverService->delete($photo->image);

        return $photo->delete();
    }

    public function cleanUp($project)
    {
        if (!$project->photos->count())
            return false;

        foreach ($project->photos as $photo)
            $this->cleanUpFile($photo);

        return true;
    }

    public function delete($id)
    {
        $project = Project::find($id);

        return DB::transaction(function () use ($project) {
            $project->delete();
        });
    }

    public function getMaxPriceRangeValue()
    {
        $max = Project::max('budget');

        return !is_null($max) ? $max : 0;
    }
}

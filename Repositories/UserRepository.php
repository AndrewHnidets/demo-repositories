<?php

namespace App\Repositories;

use App\Entity\ChatMessage;
use App\Entity\ChatRoom;
use App\Services\ImageSaverService;
use App\Services\LocalizationService;
use App\Services\LocationService;
use App\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;

class UserRepository
{
    private $imageSaverService;
    private $localizationService;
    private $locationService;

    public function __construct(ImageSaverService $imageSaverService, LocalizationService $localizationService, LocationService $locationService)
    {
        $this->imageSaverService = $imageSaverService;
        $this->localizationService = $localizationService;
        $this->locationService = $locationService;
    }

    public function getById($id)
    {
        return User::where('id', $id)
            ->with('translations')
            ->first();
    }

    public function update($user, $data)
    {
        return DB::transaction(function () use ($user, $data) {

            $formedData = $this->formData($data, $user);

            $user->update($formedData);

            $this->updateSettings($user, $data);
            $this->updateAvatar($user, $data);
            $this->updateLangFields($user, $data);
        });
    }

    private function formData($data, $user)
    {
        $formedData = array_merge(
            Arr::except($data, ['name', 'surname', 'avatar', 'country', 'city', 'administrative_area_level_1', 'lat', 'lng']),
            [
                'name' => $data['name']['uk'],
                'surname' => $data['surname']['uk'],
                'settings' => json_encode(['locale' => app()->getLocale()]),
                'locale' => app()->getLocale()
            ]);

        $city = $this->locationService->createCityWithRelations($data);
        if (isset($data['city'])) {
            $formedData['city_id'] = $city->id;
        }

        return $formedData;
    }

    public function updatePassword($id, $password)
    {
        return User::where('id', $id)->update([
            'password' => Hash::make($password)
        ]);
    }

    public function updateAvatarWithUserId($userId, $image)
    {
        return User::where('id', $userId)->update([
            'avatar' => $this->imageSaverService->store($image, USER::AVATAR_PATH)
        ]);
    }

    public function updateAvatarToDefault($user)
    {
        return $user->update([
            'avatar' => USER::DEFAULT_AVATAR
        ]);
    }

    private function updateSettings($user, $data)
    {
        $user->setting->update([
            'surname' => isset($data['hide_surname']) ? 1 : 0,
            /*'linkedin' => isset($data['hide_linkedin']) ? 1 : 0,
            'phone' => isset($data['hide_phone']) ? 1 : 0,
            'email' => isset($data['hide_email']) ? 1 : 0,
            'facebook' => isset($data['hide_facebook']) ? 1 : 0*/
        ]);
    }

    private function updateAvatar($user, $data)
    {
        if (isset($data['avatar'])) {
            if (!$user->hasDefaultAvatar())
                $this->imageSaverService->delete($user->avatar);
            $user->avatar = $this->imageSaverService->store(request()->file('avatar'), USER::AVATAR_PATH);
            $user->save();
        }
    }

    private function updateLangFields($user, $data)
    {
        $fields = ['name', 'surname'];

        $this->localizationService->saveLangFields($fields, $data, $user);
    }

    public function getAllForAdmin()
    {
        return User::select('id', 'name', 'surname')->with('translations')->get();
    }

    public function getUsersSubscribedForProject($project)
    {
        return User
            ::whereHas('userRooms', function ($query) use ($project) {
                return $query->whereHas('room', function ($query) use ($project) {
                    return $query
                        ->where('relation_type', ChatRoom::PROJECT_TYPE)
                        ->where('relation_id', $project->id)
                        ->whereHas('messages', function ($query) {
                            return $query
                                ->where('type_id', ChatMessage::REQUEST_TYPE)
                                ->where('is_accepted', 1);
                        }, '=', 1);
                });
            })
            ->where('id', '!=', $project->user_id)
            ->get();
    }
}

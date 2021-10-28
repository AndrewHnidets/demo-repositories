<?php

namespace App\Entity;

use App\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;
use TCG\Voyager\Traits\Translatable;

class Project extends Model
{
    use Translatable, SoftDeletes;

    const PAGINATE_COUNT = 12;

    protected $table = 'projects';

    protected $fillable = [
        'name', 'site', 'goal', 'in_work',
        'small_description', 'status', 'description', 'budget',
        'time_in_release', 'receive_messages', 'is_published', 'slug',
        'user_id', 'city_id', 'views', 'full_address',
    ];

    protected $translatable = [
        'name', 'small_description', 'description'
    ];

    protected $appends = [
        'status_in_words'
    ];

    public $additional_attributes = ['name_with_admin_link'];

    public function photos()
    {
        return $this->hasMany(ProjectPhoto::class, 'project_id', 'id');
    }

    public function areas()
    {
        return $this->belongsToMany(ProjectArea::class, 'area_project', 'project_id', 'project_area_id');
    }

    public function partners()
    {
        return $this->hasMany(Partner::class, 'project_id', 'id');
    }

    public function vacancies()
    {
        return $this->hasMany(Vacancy::class, 'project_id', 'id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    public function city()
    {
        return $this->belongsTo(City::class, 'city_id')->withDefault();
    }

    public function chatRoom()
    {
        return $this->morphMany('App\Entity\ChatRoom', 'relation');
    }

    /*
     * Get attribute passed in function in current lang.
     * If current lang attribute is null, try to get
     * translations in another langs. If all is null
     * return empty string.
     */
    public function getLangAttribute($attribute)
    {
        $langs = array_values(array_diff(LaravelLocalization::getSupportedLanguagesKeys(), [app()->getLocale()]));
        return $this->getTranslatedAttribute($attribute, app()->getLocale()) ?: $this->getTranslatedAttribute($attribute, $langs[0]) ?: $this->getTranslatedAttribute($attribute, $langs[1]) ?: '';
    }

    public function scopeWithAllRelation($query)
    {
        return $query->with('city', 'city.country', 'translations', 'areas', 'areas.translations', 'partners', 'partners.translations', 'partners.role', 'partners.role.translations', 'vacancies', 'vacancies.translations', 'photos', 'user.lastVerification', 'user:id', 'user.translations');
    }

    public function scopePublished($query)
    {
        return $query->where('is_published', 1);
    }

    /**
     * Get the route key for the model.
     *
     * @return string
     */
    public function getRouteKeyName()
    {
        return 'slug';
    }

    public function scopeWhereOrder($query, $params)
    {
        return $query
            ->when((isset($params['order']) && in_array($params['order'], ['asc', 'desc'])), function ($query) use ($params) {
                return $query->orderBy('created_at', $params['order']);
            }, function ($query) {
                return $query->orderBy('created_at', 'desc');
            });
    }

    public function scopeWhereSpecialistOrInvestorRooms($query, $user)
    {
        return $query->when(isset($user) && !is_null($user), function ($query) use ($user) {
            return $query
                ->whereHas('chatRoom', function ($query) use ($user) {
                    return $query
                        ->where(function ($query) use ($user) {
                            return $query->whereActiveOrPendingChat($user);
                        });
                })
                ->withUserChatRoom($user);
        });
    }

    public function scopeWithUserChatRoom($query, $user)
    {
        return $query
            ->with(['chatRoom' => function ($query) use ($user) {
                return $query
                    ->when(isset($user) && !is_null($user), function ($query) use ($user) {
                        return $query
                            ->whereHas('userRooms', function ($query) use ($user) {
                                return $query->where('user_id', $user->id)
                                    ->where('role_id', $user->last_role_id);
                            })
                            ->whereHas('userRooms', function ($query) use ($user) {
                                return $query->where('user_id', '!=', $user->id)
                                    ->where('role_id', User::INITIATOR_ROLE);
                            });
                    }, function ($query) {
                        return $query->where('id', 0);
                    });
            }])
            ->withCount(['chatRoom as activeChatRoomCount' => function ($query) use ($user) {
                return $query
                    ->when(isset($user) && !is_null($user), function ($query) use ($user) {
                        return $query
                            ->whereHas('userRooms', function ($query) use ($user) {
                                return $query->where('user_id', $user->id)
                                    ->where('role_id', $user->last_role_id);
                            })
                            ->whereHas('messages', function ($query) {
                                return $query
                                    ->where('type_id', 2)
                                    ->where('is_accepted', 1);
                            });
                    }, function ($query) {
                        return $query->where('id', 0);
                    });
            }]);
    }

    public function scopeWhereFilterStatus($query, $params)
    {
        return $query->when(isset($params['status']) && !is_null($params['status'] && in_array($params['status'], [1, 2])), function ($query) use ($params) {
            return $query->where('status', $params['status']);
        });
    }

    public function scopeWhereFilterProjectArea($query, $params)
    {
        return $query->when(isset($params['project_area']) && !is_null($params['project_area']), function ($query) use ($params) {
            return $query->whereHas('areas', function ($query) use ($params) {
                return $query->whereIn('project_area_id', $params['project_area']);
            });
        });
    }

    public function scopeWhereFilterBudget($query, $params)
    {
        return $query->when(isset($params['budget']) && !is_null($params['budget']) && in_array($params['budget'], [1, 2, 3, 4]), function ($query) use ($params) {
            switch ($params['budget']) {
                case 1: //  < 10k
                    return $query->where('budget', '<', 10000);
                    break;
                case 2: // 10k - 50k
                    return $query
                        ->where('budget', '>=', 10000)
                        ->where('budget', '<=', 50000);
                    break;
                case 3: // 50k - 100k
                    return $query
                        ->where('budget', '>=', 50000)
                        ->where('budget', '<=', 100000);
                    break;
                case 4: // > 100k
                    return $query->where('budget', '>', 100000);
                    break;
            }
        });
    }

    public function scopeWhereFilterGoal($query, $params)
    {
        return $query->when((isset($params['goal']) && is_array($params['goal']) && ['1', '3', '2'] != $params['goal']), function ($query) use ($params) {
            return $query->where(function ($query) use ($params) {
                foreach ($params['goal'] as $search) {
                    $query->orWhere('goal', 'like', '%' . $search . '%');
                }
            });
        });
    }

    public function scopeWhereFilterProjectRole($query, $params)
    {
        return $query->when(isset($params['project_role']) && !is_null($params['project_role']), function ($query) use ($params) {
            return $query
                ->whereHas('partners', function ($query) use ($params) {
                    return $query->whereHas('role', function ($query) use ($params) {
                        return $query->whereIn('id', $params['project_role']);
                    });
                });
        });
    }

    public function scopeWhereFilterTimeInRelease($query, $params)
    {
        return $query->when(isset($params['time_in_release']) && !is_null($params['time_in_release']), function ($query) use ($params) {
            return $query->where('time_in_release', $params['time_in_release']);
        });
    }

    public function scopeWhereFilterInternational($query, $params)
    {
        return $query->when(isset($params['international']) && $params['international'] == 1, function ($query) use ($params) {
            return $query->whereHas('translations', function ($query) {
                return $query
                    ->where('locale', 'en')
                    ->where('column_name', 'name')
                    ->whereNotNull('value')
                    ->where('value', '!=', '');
            });
        });
    }

    public function scopeWhereFilterCity($query, $params)
    {
        return $query->when(isset($params['city']) && !is_null($params['city']), function ($query) use ($params) {
            $parsedData = json_decode($params['city']);

            return $query
                ->when(isset($parsedData->administrative_area_level_1) && !is_null($parsedData->administrative_area_level_1), function ($query) use ($parsedData) {
                    return $query->whereHas('city', function ($query) use ($parsedData) {
                        return $query->whereHas('area', function ($query) use ($parsedData) {
                            return $query->where('name', $parsedData->administrative_area_level_1);
                        });
                    });
                }, function ($query) use ($parsedData) {
                    return $query
                        ->when(isset($parsedData->locality) && !is_null($parsedData->locality), function ($query) use ($parsedData) {
                            return $query->whereHas('city', function ($query) use ($parsedData) {
                                return $query->where('name', $parsedData->locality);
                            });
                        });
                })
                ->when(!isset($parsedData->locality) && !isset($parsedData->administrative_area_level_1) && isset($parsedData->country) && !is_null($parsedData->country), function ($query) use ($parsedData) {
                    return $query->whereHas('city', function ($query) use ($parsedData) {
                        return $query->whereHas('country', function ($query) use ($parsedData) {
                            return $query->where('name', $parsedData->country);
                        });
                    });
                });
        });
    }

    public function scopeWhereFilterPriceRange($query, $params)
    {
        return $query->when(isset($params['price_range_min']) && isset($params['price_range_max']), function ($query) use ($params) {
            return $query
                ->where('budget', '>=', intval($params['price_range_min']))
                ->where('budget', '<=', intval($params['price_range_max']));
        });
    }

    public function scopeWhereFilterSearch($query, $params)
    {
        return $query->when(isset($params['search']) && !is_null($params['search']), function ($query) use ($params) {

            $attributes = ['name', 'small_description', 'description'];

            foreach ($attributes as $attribute)
                $query
                    ->when($attribute == $attributes[0], function ($query) use ($params, $attribute) {
                        $query->where($attribute, 'like', '%' . $params['search'] . '%');
                    }, function ($query) use ($params, $attribute) {
                        $query->orWhere($attribute, 'like', '%' . $params['search'] . '%');
                    })
                    ->orWhereHas('translations', function ($query) use ($params, $attribute) {
                        return $query
                            ->whereIn('locale', ['en', 'ru'])
                            ->where('column_name', $attribute)
                            ->where('value', 'like', '%' . $params['search'] . '%');
                    });

            return $query;
        });
    }

    public function isPublished()
    {
        return $this->is_published;
    }

    public function isSameSlug($slug)
    {
        return $this->slug == $slug;
    }

    public function addGoal($goalId)
    {
        if (!$this->checkIfHaveGoalWithId($goalId)) {
            $goals = explode(',', $this->goal);
            array_push($goals, $goalId);

            $this->goal = implode($goals, ',');

            $this->save();
        }

    }

    public function removeGoal($goalId)
    {
        if ($this->checkIfHaveGoalWithId($goalId)) {
            $goals = explode(',', $this->goal);
            $goals = array_diff($goals, [$goalId]);

            $this->goal = implode($goals, ',');

            $this->save();
        }
    }

    public function checkIfHaveGoalWithId($goalId)
    {
        return $this->goal ? strpbrk($goalId, $this->goal) : false;
    }

    public function checkHasActiveChat($user)
    {
        return $this->chatRoom()->whereActiveChat($user)->exists();
    }

    public function isUserProject($user)
    {
        return isset($user) && !is_null($user) && ($this->user_id == $user->id);
    }

    public function getAdminViewLink()
    {
        return '/admin/projects/' . $this->id;
    }

    public function getClassNameForAdmin()
    {
        return 'Проект';
    }

    public function canBrowseSurname($user = null)
    {
        return $this->isUserProject($user) ? $this->user->canBrowseSurname() : ($this->user->canBrowseSurname() || $this->activeChatRoomCount);
    }

    public function canBrowsePhone($user = null)
    {
        return $this->isUserProject($user) ? $this->user->canBrowsePhone() : ($this->user->canBrowsePhone() || $this->activeChatRoomCount);
    }

    public function canBrowseEmail($user = null)
    {
        return $this->isUserProject($user) ? $this->user->canBrowseEmail() : ($this->user->canBrowseEmail() || $this->activeChatRoomCount);
    }

    public function canBrowseFacebook($user = null)
    {
        return $this->isUserProject($user) ? $this->user->canBrowseFacebook() : ($this->user->canBrowseFacebook() || $this->activeChatRoomCount);
    }

    public function canBrowseLinkedin($user = null)
    {
        return $this->isUserProject($user) ? $this->user->canBrowseLinkedin() : ($this->user->canBrowseLinkedin() || $this->activeChatRoomCount);
    }

    public function hasActiveChat($user = null)
    {
        return $this->isUserProject($user) ? false : $this->chatRoom->count();
    }
}



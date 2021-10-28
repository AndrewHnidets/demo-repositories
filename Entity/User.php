<?php

namespace App;

use App\Entity\Blog;
use App\Entity\BlogCommentary;
use App\Entity\ChatRoom;
use App\Entity\ChatUserRoom;
use App\Entity\City;
use App\Entity\InvestorResume;
use App\Entity\Project;
use App\Entity\SpecialistResume;
use App\Entity\UserSetting;
use App\Entity\UserVerification;
use App\Notifications\Auth\ResetPassword;
use App\Notifications\Auth\VerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Cache;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;
use TCG\Voyager\Models\Role;
use TCG\Voyager\Traits\Translatable;

class User extends \TCG\Voyager\Models\User implements MustVerifyEmail, HasLocalePreference
{
    use Notifiable, Translatable, SoftDeletes;

    const AVATAR_PATH = 'public/users/avatars';
    const DEFAULT_AVATAR = 'users/default.svg';
    const NEWLY_ROLE = 2;
    const SPECIALIST_ROLE = 3;
    const INVESTOR_ROLE = 4;
    const INITIATOR_ROLE = 5;

    protected $fillable = [
        'name', 'surname', 'email', 'password',
        'login', 'phone', 'country', 'city',
        'address', 'linkedin', 'facebook', 'last_role_id',
        'avatar', 'locale', 'role_id', 'api_token',
        'city_id'
    ];

    protected $hidden = [
        'password', 'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    protected $translatable = [
        'name', 'surname'
    ];

    protected $appends = [
        'full_name', 'is_online'
    ];

    public $additional_attributes = ['full_name', 'full_name_with_admin_link'];

    /**
     * The channels the user receives notification broadcasts on.
     *
     * @return string
     */
    public function receivesBroadcastNotificationsOn()
    {
        return 'App.User.' . $this->id;
    }

    public function blogCommentaries()
    {
        return $this->hasMany(BlogCommentary::class, 'user_id', 'id');
    }

    public function verifications()
    {
        return $this->hasMany(UserVerification::class, 'user_id', 'id')->with('status');
    }

    public function lastVerification()
    {
        return $this->hasOne(UserVerification::class, 'user_id', 'id')->latest();
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'user_roles', 'user_id', 'role_id');
    }

    public function setting()
    {
        return $this->hasOne(UserSetting::class, 'user_id', 'id');
    }

    public function projects()
    {
        return $this->hasMany(Project::class, 'user_id', 'id');
    }

    public function investorResume()
    {
        return $this->hasOne(InvestorResume::class, 'user_id', 'id');
    }

    public function specialistResume()
    {
        return $this->hasOne(SpecialistResume::class, 'user_id', 'id');
    }

    public function blogs()
    {
        return $this->hasMany(Blog::class, 'user_id', 'id');
    }

    public function specialistBlogs()
    {
        return $this->hasMany(Blog::class, 'user_id', 'id')->where('role_id', self::SPECIALIST_ROLE);
    }

    public function userRooms()
    {
        return $this->hasMany(ChatUserRoom::class, 'user_id', 'id');
    }

    public function city()
    {
        return $this->belongsTo(City::class, 'city_id')->withDefault();
    }

    /**
     * Send the email verification notification.
     *
     * @return void
     */
    public function sendEmailVerificationNotification()
    {
//        \Log::info('email created for user - ' . $this->id);
        $this->notify(new VerifyEmail());
    }

    /**
     * Send the password reset notification.
     *
     * @param string $token
     * @return void
     */
    public function sendPasswordResetNotification($token)
    {
        $this->notify(new ResetPassword($token));
    }

    /**
     * Check if user is online
     *
     * @return bool
     */
    public function isOnline()
    {
        return Cache::has('user-is-online-' . $this->id);
    }

    /**
     * Check if user is verified
     *
     * @return bool
     */
    public function isVerified()
    {
        return $this->lastVerification ? $this->lastVerification->isVerified() : false;
    }

    /**
     * Get the user's preferred locale.
     *
     * @return string
     */
    public function preferredLocale()
    {
        return $this->locale;
    }

    /*
     *  Update users last role id
     */
    public function updateLastRoleId($roleId)
    {
        $this->last_role_id = $roleId;
        return $this->save();
    }

    /*
     * Check if user last role is specialist
     */
    public function isSpecialist()
    {
        return $this->last_role_id == self::SPECIALIST_ROLE;
    }

    /*
     * Check if user last role is initiator
     */
    public function isInitiator()
    {
        return $this->last_role_id == self::INITIATOR_ROLE;
    }

    /*
     * Check if user last role is investor
     */
    public function isInvestor()
    {
        return $this->last_role_id == self::INVESTOR_ROLE;
    }

    /*
     * Check if user last role is a new registered (dont have any role)
     */
    public function isNewly()
    {
        return $this->last_role_id == self::NEWLY_ROLE;
    }

    public function isAdmin()
    {
        return ($this->role_id == 1) || ($this->email == "admin@admin.com");
    }

    /*
     * Get users profile fullness percentage
     */
    public function getProfileFullnessPercentage()
    {
        $fields = [
            'name' => $this->getLangAttribute('name'),
            'surname' => $this->getLangAttribute('surname'),
            'email' => $this->email,
            'phone' => $this->phone,
            'linkedin' => $this->linkedin,
            'facebook' => $this->facebook,
            'avatar' => $this->avatar
        ];

        $maxQuantity = count($fields);
        $notNullFields = $this->getNotNullFieldsCount($fields);

        return round($notNullFields / $maxQuantity * 100);
    }

    /*
     * Support function. Get not null users fields count
     */
    private function getNotNullFieldsCount($fields)
    {
        $notNullFields = 0;
        foreach ($fields as $field)
            if (!is_null($field))
                $notNullFields++;

        return $notNullFields;
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
        return $this->getTranslatedAttribute($attribute, app()->getLocale()) ?: $this->getTranslatedAttribute($attribute, $langs[1]) ?: $this->getTranslatedAttribute($attribute, $langs[0]) ?: '';
    }

    /*
     * Check if user has access to full cabinet
     */
    public function hasCabinetAccess()
    {
        return !is_null($this->last_role_id) && $this->last_role_id != 2;
    }

    public function getFullNameAttribute()
    {
        return $this->getLangAttribute('name') . ' ' . $this->getLangAttribute('surname');
    }

    public function getFullAddressAttribute()
    {
        $array = array_filter([
            $this->city->name,
            $this->city->area->name,
            $this->city->country->name
        ]);

        return implode(', ', $array);
    }

    public function getIsOnlineAttribute()
    {
        return $this->isOnline();
    }

    public function getFullNameWithRestriction()
    {
        $string = $this->getLangAttribute('name');

        if (!$this->setting->surname)
            $string .= ' ' . $this->getLangAttribute('surname');

        return $string;
    }

    public function canBrowseSurname()
    {
        return !$this->setting->surname;
    }

    public function canBrowseEmail()
    {
        return !$this->setting->email;
    }

    public function canBrowsePhone()
    {
        return !$this->setting->phone;
    }

    public function canBrowseFacebook()
    {
        return !($this->setting->facebook) && $this->facebook;
    }

    public function canBrowseLinkedin()
    {
        return !($this->setting->linkedin) && $this->linkedin;
    }

    /*
     * check if user has active project
     */
    public function hasActiveProjects()
    {
        return $this->projects()->published()->count();
    }

    /*
     * check if user has active investor resume
     */
    public function hasActiveInvestorResume()
    {
        return $this->investorResume()->published()->count();
    }

    /*
     * check if user has active specialist resume
     */
    public function hasActiveSpecialistResume()
    {
        return $this->specialistResume()->count();
    }

    /*
     * depends on current role
     * get error message if user has no active resumes or projects
     * return empty string if has
     */
    public function getErrorMessageIfNoRoleEntityPresent()
    {
        $message = '';

        switch (auth()->user()->last_role_id) {
            case User::SPECIALIST_ROLE:
                if (!auth()->user()->hasActiveSpecialistResume())
                    $message = __('validation.attributes.create_request_fail_role', ['role' => __('validation.attributes.create_request_fail_role_person_specialist')]);
                break;
            case User::INVESTOR_ROLE:
                if (!auth()->user()->hasActiveInvestorResume())
                    $message = __('validation.attributes.create_request_fail_role', ['role' => __('validation.attributes.create_request_fail_role_person_investor')]);
                break;
            case User::INITIATOR_ROLE:
                if (!auth()->user()->hasActiveProjects())
                    $message = __('validation.attributes.create_request_fail_role', ['role' => __('validation.attributes.create_request_fail_role_person_initiator')]);
                break;
            case User::NEWLY_ROLE:
                $message = __('validation.attributes.create_request_fail_role', ['role' => '']);
                break;
        }

        return $message;
    }

    public function hasDefaultAvatar()
    {
        return $this->avatar == self::DEFAULT_AVATAR;
    }

    public function hasActiveOrInactiveProject()
    {
        return $this->projects()->count();
    }

    public function hasActiveOrInactiveInvestorResume()
    {
        return $this->investorResume()->count();
    }

    public function hasActiveOrInactiveSpecialistResume()
    {
        return $this->specialistResume()->count();
    }

    public function getCountOfSuccessChatUserRooms()
    {
        return ChatRoom
            ::whereHas('userRooms', function ($query) {
                return $query->where('user_id', $this->id);
            })
            ->whereHas('userRooms', function ($query) {
                return $query->where('is_succeeded', 1);
            }, '=', 2)
            ->count();
    }

    public function getCountOfNonSuccessChatUserRooms()
    {
        return ChatRoom
            ::whereHas('userRooms', function ($query) {
                return $query->where('user_id', $this->id);
            })
            ->whereHas('userRooms', function ($query) {
                return $query->where('is_succeeded', 2);
            }, '=', 2)
            ->count();
    }
}

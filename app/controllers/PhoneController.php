<?php

use Carbon\Carbon;

class PhoneController extends \BaseController {

  /**
   * Purge old phones from database
   */
  private function purgePhones()
  {
  	// Delete all phones without activity within the past week
    Phone::where('updated_at', '<', Carbon::now()->subWeek())->delete();
  	// Delete all Guest phones without activity within the past day
    User::Guest()->phones()->where('updated_at', '<', Carbon::now()->subDay())->delete();
  }
  
  /**
   * Show all phones for the logged-in user
   */
  public function showPhones()
  {
    $this->purgePhones();

    $phones = Phone::where('user_id', Auth::user()->id)
      ->orWhere('user_id', User::guestId())
      ->orderBy('created_at', 'desc')
      ->orderBy('id', 'desc')
      ->get();

    foreach($phones as $phone) {
    	$phone->location = $phone->locations()->orderBy('created_at', 'desc')->first();
    	if(!$phone->location) {
    		$phone->location = new Location;
        $phone->location->location = '';
        $phone->location->accuracy = '';
        $phone->location->altitude = '';
        $phone->location->altitudeAccuracy = '';
        $phone->location->heading = '';
        $phone->location->speed = '';
        $phone->location->location_time = '';
        $phone->location->created_at = '';
    	}
    }
    return View::make('phones', array('phones' => $phones, 'success' => Session::get('success')));
  }

  /**
   * Show all locations for given phone
   * Verify phone belongs to user or guest
   */
  public function showPhone(Phone $phone)
  {
  	if( !($phone->user->id == Auth::user()->id || $phone->user->id == User::guestId()) ) {
  		App::abort(401, 'You are not authorized.');
  	}

  	$locations = $phone->locations()->orderBy('created_at', 'desc')->get();

  	return View::make('phone', array('phone' => $phone, 'locations' => $locations));
  }

  /**
   * Send SMS message
   */
  public function sendSMS()
  {
  	// Make input available in future requests
  	Input::flash();

  	// Validate input
	  $validator = Validator::make(
			Input::all(), 
			array(
				'phone' => array('required', 'phone:US'),
				));

	  if ($validator->fails())
    {
      return Redirect::back()->withErrors($validator);
    }

    // Use default message if user left it blank
    $message = Input::get('message');
    if (trim($message) == '') {
      $message = 'Tap link to send location to SAR:';
    }

  	// Add phone to database
  	$phone = new Phone;
  	$phone->number = Input::get('phone');
  	$phone = Auth::user()->phones()->save($phone);

    // Send SMS with token using Twilio
    $twilio = new Services_Twilio($_ENV['TWILIO_ACCOUNT_SID'], $_ENV['TWILIO_AUTH_TOKEN']);
    $sms = $twilio->account->messages->sendMessage(
      $_ENV['TWILIO_NUMBER'], 
      $phone->number,
      sprintf('%s %s/%s', $message, route('get-location'), $phone->token)
    );

		return Redirect::route('index')->with('success', 'SMS sent to ' . $phone->number_pretty);
	}

  /**
   * Parse token and return phone model
   * Create a new phone if need be
   */
  private function getPhone($token)
  {
    // Base64url-decode ID
    $id = (int) base64url_decode($token);
    $phone = Phone::find($id);

    if(!$phone)
    {
      // Use IP address to generate ID
      $userString = $_SERVER['REMOTE_ADDR'] . ' ' . $_SERVER['HTTP_USER_AGENT'];
      $phone = new Phone;
      $phone->email = $userString;
      $phone->created_at = Carbon::now();
      User::Guest()->phones()->save($phone);
    }

    return $phone;
  }

  /**
   * Prompt subject device for location
   */
  public function getLocation($token = null)
  {
    $phone = $this->getPhone($token);
    return View::make('subject', array('token' => base64url_encode($phone->id)));
  }

  /**
   * Convert 'null' to null, all other values to float
   */
  private function toFloat($input)
  {
    if ($input == null)
    {
      return null;
    }
    else if (strtolower($input) == 'null')
    {
      return null;
    }
    else
    {
      return floatval($input);
    }
  }

  /**
   * Get location from subject device
   */
  public function setLocation($token, $longitude, $latitude, $altitude, $accuracy, $altitudeAccuracy, $heading, $speed, $location_time)
  {
    // This route is called via AJAX
    // Update database with location using ID
    // Return a confirmation to user
    
    // Register catch-all error handler, just for this request
    App::error(function(Exception $exception, $code)
    {
      echo('Error: <a href="./">Location not received. Tap here to reload page.</a>');
    });
    
    $phone = $this->getPhone($token);
    $location = new Location;

    $location->location = sprintf('(%s, %s)', $latitude, $longitude);
    $location->altitude = $this->toFloat($altitude);
    $location->accuracy = $this->toFloat($accuracy);
    $location->altitudeAccuracy = $this->toFloat($altitudeAccuracy);
    $location->heading = $this->toFloat($heading);
    $location->speed = $this->toFloat($speed);
    $location->location_time = intval($location_time) / 1000;

    $phone->locations()->save($location);

    echo('Your location has been received by Search &amp; Rescue.');
  }

}
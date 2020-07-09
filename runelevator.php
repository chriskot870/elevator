#!/usr/bin/php
<?php

/*
 * This is command line simulator of an Elevator.
 * 
 * It was developed and tested on a Linux Fedora 29 host using PHP 7.2.15.
 * 
 * To run the simulator use
 * 
 * $ php runelevator.php
 * 
 * There are two types of elevator riders. Those outside the elevator that
 * are on a floor and want to be picked up to go to either a floor above or
 * below the one they are on. A rider inside the elevator wants the elevator
 * to take them to a floor.
 * 
 * The simulator will show a status line and ask for a stop request.
 * 
 * For a rider outside the elevator that wants to be picked up from a specific
 * floor you enter the floor that the rider is currently on, preceded by a "+"
 * if they want to go up from the floor they are on or "-" if they want to go down.
 * 
 * For a rider inside the elevator you only need to enter the floor you want to
 * be taken to.
 * 
 * Here are some examples:
 * +3 - Rider is on floor 3 and wants to be taken to a floor above floor 3
 * -3 - Rider is on floor 3 and wants to be taken to a floor below floor 3
 * 3  - Rider is in elevator and wants to be taken to floor 3
 * 
 * The implementation uses select and SIGALRM to provide an event driven model to
 * move the elevator from floor to floor and open and close the doors. The preset
 * values are 5 seconds to move from one floor to another and to allow boarding
 * and unboarding (door open) for 5 seconds.
 * 
 * You can make stop requests at any time and they will be queued into a floorstop queue.
 * 
 * For outside rider requests the elevator will only stop if the elevator is traveling in
 * the direction the rider entered, "+" for up and "-" for down or if the floor is the
 * last stop in that direction, independent of the requested direction. For inside rider
 * requests the elevator will stop at the requested floor when the elevator is going in any
 * direction.
 * 
 * Once the elevator starts in a direction it will continue in that direction until it stops
 * at a floor and there are no more stop requests in that direction. If at that point there
 * are stop requests in the opposite direction the elevator will change directions to satisfy
 * those requests.
 * 
 * All of this means that if the elevator is at floor 1 and a stop request is made for floor 5
 * the elevator will move in the up direction to satisfy that stop request. If while moving and
 * prior to getting to floor 3, an outside rider on floor 3 wants to go down, entered -3, the
 * elevator will pass by floor 3 on the way to floor 5. it will stop at floor 5. If no stop
 * requests are made for floors above floor 5 prior to the door shutting, the elevator will then
 * go down to floor 3 to satisfy the -3 stop request.
 */
/*
 * Needed for handling signals
 */
declare(ticks=1);
/*
 * Initialize Elevator Object to have 10 floors
 */
$elevator = new Elevator(10);

$errmsg = NULL;
do {
    /*
     * Show status and prompt for a floor
     */
    do {
        /*
         * A cheap way to clear the screen and re-print the status line
         */
        system("clear");
        printf("Current Floor: %3d Direction: %4s Status: %7s\n", $elevator->currentfloor(), $elevator->direction(),$elevator->status());
        if ( !is_null($errmsg) ) {
            printf("%s\n", $errmsg);
        }
        printf("Select a floor to send elevator to: ");
        /*
         * Get the input
         */
        $read = array(STDIN);
        $write = NULL;
        $except = NULL;
        $timeout = NULL;
        $val = stream_select($read, $write, $except, $timeout);
        /*
         * If $val is false we handled a SIGALRM so loop back around and repritn Ststaus line-
         */
    } while (!$val);
    /*
     * If select is true then a new stop request has been made so add to the list.
     */
    $input = trim(fgets(STDIN));
    /*
     * Check if there is a preceding + or minus to determine if someone is on a floor requesting service.
     * If there isn't a + or - then they are in the elevator requesting to go to a floor
     */
    if ( substr($input,0,1) == "+") {
        $direction = "up";
        $floor = substr($input,1);
    }elseif ( substr($input,0,1) == "-") {
        $direction = "down";
        $floor = substr($input,1);
    }else {
        $direction = "any";
        $floor = $input;
    }
    /*
     * Sanity check the input
     */
    if ( !is_numeric($floor) || $floor < $elevator->bottomfloor() || $floor > $elevator->topfloor() 
        || ($floor == $elevator->bottomfloor() && $direction == "down")
        || ($floor == $elevator->topfloor() && $direction == "up")) {
        /*
         * Got an error so define an error message and show status line and wait for input
         */
        if ( $floor == $elevator->bottomfloor() && $direction == "down" ) {
            $errmsg = sprintf("Input Error: No down button for bottom floor: %s", $input);
        } elseif ( $floor == $elevator->topfloor() && $direction == "up" ) {
            $errmsg = sprintf("Input Error: No up button for top floor: %s", $input);
        } else {
            $errmsg = sprintf("Input Error: floor needs to be number between %d and %d possibly preceded with  + or - : %s", $elevator->bottomfloor(), $elevator->topfloor(), $input);
        }
        continue;
    }
    /*
     * Input is OK so clear errror message
     */
    $errmsg = NULL;
    if ( $elevator->status() == "stopped" && $elevator->currentfloor() == $floor ) {
        /*
         * The elevator is already stopped at the floor that was requested either to be taken to
         * or to be picked up at.
         */
        if ( $elevator->doorstatus() == "open" ) {
            /*
             * If the door is open on their floor with elevator going their way then assume they got on
             * Do not add another stop
             */
            continue;
        }
        /*
         * If the door is closed on the floor requested to be picked up on and the floor is the
         * bottom floor or the top floor set the direction to the only possible way
         */
        if ( $direction != "any" ) {
            if ( $floor == $elevator->bottomfloor() ) {
                $elevator->direction("up");
            } elseif ( $floor == $elevator->topfloor() ) {
                $elevator->direction("down");
            } else {
                $elevator->direction($direction);
            }
        }
        /*
         * Open the door
         */
        $elevator->doorstatus("open");
        /*
         * Set an alarm to close the door
         */
        pcntl_signal(SIGALRM, "doorclose");
        pcntl_alarm($elevator->timetoboard());
        continue;
    }
    /*
     * Put the floor request on the stop request queue
     */
    $elevator->stoprequest($direction, (integer)$floor);
    /*
     * If it's for the current floor the $elevatorfloorstops will be empty
     * If the floor stop list is not empty and the elevator is stopped with door closed start it again
     */
    if ( $elevator->hasstops() && $elevator->status() == "stopped") {
        /*
         * Start the elevator
         */
        $elevator->start();
        pcntl_signal(SIGALRM, "atnextfloor");
        pcntl_alarm($elevator->timetofloor());
    }
} while(true);

exit(0);

function atnextfloor($signo, $siginfo) {
    /*
     * This gets called via sigalarm and indicates the elevator has moved one floor
     */
    global $elevator;
    /*
     * Sanity check that it's from SIGALRM and not some other signal
     */
    if ( $signo != SIGALRM ) {
        return;
    }
    /*
     * Move to the next floor
     */
    $elevator->floormove();
    /*
     * If after the move the elevator hasn't stopped have it move on to next floor
     */
    if ( !$elevator->isstopped() ) {
        /*
         * Keep moving to next floor by setting sigalarm again
         */
        pcntl_signal(SIGALRM, "atnextfloor");
        pcntl_alarm($elevator->timetofloor());
        return;
    }
    
    /*
     * We are stopped at a floor
     * open the door
     */
    $elevator->doorstatus("open");
    /*
     * Set an alarm to close the door
     */
    pcntl_signal(SIGALRM, "doorclose");
    pcntl_alarm($elevator->timetoboard());
    return;
}

function doorclose($signo, $siginfo) {
    /*
     * when the elevator stops on a floor it opened the door
     * We get here because it is now time to close the door
     */
    global $elevator;
    /*
     * Sanity check that it's from SIGALRM and not some other signal
     */
    if ( $signo != SIGALRM ) {
        return;
    }
    /*
     * Set the door to close
     */
    $elevator->doorstatus("closed");
    /*
     * We got here because we stopped at a floor if there are still floors to stop
     * at then start up again. Otherwise, just stay here till a new floor request
     * starts the elevator again.
     */
    if ( !$elevator->hasstops()) {
        /*
         * No stops so stay stopped. Turn off the alarm
         */
        $elevator->stop();
        pcntl_signal(SIGALRM, 0);
        return;
    }
    /*
     * If we get here there are more stops to make so go to next floor
     */
    $elevator->start();
    pcntl_signal(SIGALRM, "atnextfloor");
    pcntl_alarm($elevator->timetofloor());
    
    return;
}
    
class Elevator {
    
    private $bottomfloor = 1;
    /*
     * The top floor gets set to final value in __construct
     */
    private $topfloor = 1;
    private $currentfloor = 1;
    /*
     * Is the elevator moving or stopped
     */
    private $stopped = true;
    /*
     * Which direction is it going
     */
    private $direction = "up";
    /*
     * Which floors need to be stopped at
     */
    private $floorstops = array();
    /*
     * The status of the door, initialized to close
     */
    private $doorstatus = "closed";
    /*
     * Set a time to go from floor to floor in seconds for simulation
     */
    private $timetofloor = 5;
    /*
     * Provide a time to board when we stop at a floor, how long does the door stay open
     */
    private $timetoboard = 5;
    
    function __construct($maxfloors = NULL) {
        /*
         * Set a default max floors of 10
         */
        if (!is_null($maxfloors) ) {
            $maxfloors = 10;
            $this->topfloor = $maxfloors;
        }
        /*
         * Initialize all floor stops to be false
         */
        for($n = 1; $n <= $maxfloors; $n++) {
            $stop = array();
            /*
             * The up direction means some one is outside elevator and wants to go down from this floor
             */
            $stop["up"] = false;
            /*
             * The down direction means someone is outside elevater and wants to go down from this floor
             */
            $stop["down"] = false;
            /*
             * The any direction means someone is inside the elevator and wants to be taken to the floor
             */
            $stop["any"] = false;
            $this->floorstops[$n] = $stop;
        }

    }
    
    function stoprequest($direction, $floor) {
        /*
         * We only want digits passed here so check for invalid types being passed
         */
        if ( gettype($floor) != "integer" ) {
            return false;
        }
        /*
         * Make sure the floor requested is in range
         */
        if ( $floor > $this->topfloor || $floor < $this->bottomfloor ) {
            return false;
        }
        /*
         * When a stop is requested for a stop and it is already
         * on the list of floors to stop at, don't bother adding it because we are going
         * to already stop there.
         */
        if ( $this->floorstops[$floor][$direction] ) {
            return false;
        }
        /*
         * If the elevator is stopped and the current floor is the floor being requested don't
         * set it to stop there because it already is stopped there
         */
        if ( $this->stopped && $this->currentfloor == $floor && ($this->direction() == $direction || $direction == "any") ) {
            return false;
        }
        /*
         * if it's not on the list of floors to stop set that floor,direction to true
         */
        $this->floorstops[$floor][$direction] = true;
        
        return true;
        
    }
    
    function floormove() {
        /*
         * The simulation time interval has passed and the elevator has moved to the next floor
         * If we are going up the current floor should be incremented, if we are going down then decrement
         */
        if ( $this->direction == "up" ) {
            if ($this->currentfloor != $this->topfloor) {
                /*
                 * We have moved up a floor
                 */
                $this->currentfloor++;
            } 
        } else {
            if ($this->currentfloor != $this->bottomfloor) {
                /*
                 * We have moved down a floor
                 */
                $this->currentfloor--;
            }
        }
        /*
         * At this point we have the current floor
         * Check if we need to stop at this floor
         */
        if ( $this->floorstops[$this->currentfloor]["any"] ) {
            /*
             * Somebody wants off so stop and clear the "any" direction in floorstops for this floor
             */
            $this->stop();
            $this->floorstops[$this->currentfloor]["any"] = false;
        }
        /*
         * If someone requested to get on at this floor and wants to continue
         * in existing direction we expect they got on so remove stop and keep direction
         */
        if ( $this->floorstops[$this->currentfloor][$this->direction] ) {
            $this->stop();
            $this->floorstops[$this->currentfloor][$this->direction] = false;
        } else {
            /*
             * We assumed above that if the guy above said he wants to go up he will go up so we didn't change directions
             * If there wasn't someone above check if there was someone that wanted to go the opposite direction.
             * If there aren't any more stops above we can change directions so they know the elevator is going
             * their direction and they can get on
             */
            $currentdir = $this->direction;
            if ( $currentdir == "up" && $this->floorstops[$this->currentfloor]["down"] && !$this->stopsabovefloor($this->currentfloor) ) {
                /*
                 * No more stops above and someone wants to get in and go down so change direction to down
                 */
                $this->direction = "down";
                /*
                 * We assume the person that wants to go down got on so clear the down stop
                 */
                $this->stop();
                $this->floorstops[$this->currentfloor]["down"] = false;
            }
            if ( $currentdir == "down" && $this->floorstops[$this->currentfloor]["up"] && !$this->stopsbelowfloor($this->currentfloor) ) {
                /*
                 * No more stops below and someone wants to get in and go up so change direction to up
                 */
                $this->direction = "up";
                /*
                 * We assume the person that wants to go up got on so clear the up stop
                 */
                $this->stop();
                $this->floorstops[$this->currentfloor]["up"] = false;
            }  
        }
        /*
         * If we get here and there are no stops to go to stop the elevator
         */
        if ( !$this->hasstops() ) {
            $this->stop();
        }
        
        return;
    }
    
    function hasstops() {
        /*
         * Walk through the floorstops and see if any are true
         */
        for( $n = $this->bottomfloor; $n <= $this->topfloor; $n++ ) {
            if ( $this->floorstops[$n]["up"] || $this->floorstops[$n]["down"] || $this->floorstops[$n]["any"]) return true;
        }
        
        return false;
    }
    
    function stopsabovefloor($floor) {
        /*
         * See if there are any stops above the floor passed in
         */
        if ( $floor == $this->topfloor ) return false;
        for( $n = $floor + 1; $n <= $this->topfloor; $n++ ) {
            if ( $this->floorstops[$n]["up"] || $this->floorstops[$n]["down"] || $this->floorstops[$n]["any"]) return true;
        }
        
        return false;
    }
    
    function stopsbelowfloor($floor) {
        /*
         * See if there are any stops below the floor passed in
         */
        if ( $floor == $this->bottomfloor ) return false;
        for( $n = $floor - 1; $n >= $this->bottomfloor; $n-- ) {
            if ( $this->floorstops[$n]["up"] || $this->floorstops[$n]["down"] || $this->floorstops[$n]["any"]) return true;
        }
        
        return false;
    }
    
    function currentfloor() {
        /*
         * Return the current floor value
         */
        
        return $this->currentfloor;

    }
    
    function topfloor() {
        /*
         * Return top floor value
         */
       return $this->topfloor;
    }
    
    function bottomfloor() {
        /*
         * Return bottom floor value
         */
        return $this->bottomfloor;
    }
    
    function direction($dir = NULL) {
        /*
         * If $dir is not NULL set the direction
         * If it is NULL just return the current direction
         */
        if ( !is_null($dir) ) {
            /*
             * Check if a valid value
             * if not don't change the value and retunr what it was
             */
            if ( $dir == "up" || $dir = "down" ) {
                $this->direction = $dir;
            }
        }
        return $this->direction;
    }
    
    function isstopped() {
        /*
         * Return the whether the elevator is stopped or not
         */
        
       return $this->stopped;
    }
    
    function start() {
        /*
         * Don't start if there are no stops or if we are already not stopped
         */
        if ( !$this->hasstops() || !$this->isstopped() ) {
            return;
        }
        /*
         * If there are stops check the direction we should go
         */
        if ( $this->direction == "up" ) {
            /*
             * If we are currently going up and there are no stops above where we are but there are stops below where we are change to down
             */
            if ( !$this->stopsabovefloor($this->currentfloor) && $this->stopsbelowfloor($this->currentfloor) ) {
                $this->direction = "down";
            }
        } elseif ( $this->direction == "down") {
            /*
             * If we are currently going down and there are no stops below where we are but there are stops above where we are change to up
             */
            if (!$this->stopsbelowfloor($this->currentfloor) &&  $this->stopsabovefloor($this->currentfloor) ) {
               $this->direction = "up";
            }
        }
        /*
         * We are moving so set stopped to false
         */
        $this->stopped = false;
        
        return $this->stopped;
    }
    
    function stop() {
        /*
         * Set the Elevator to stop
         */
        $this->stopped = true;
        
        return $this->stopped;
    }
    
    function doorstatus($status = NULL) {
        /*
         * If $status is NULL then return current door status
         * Otherwise, set the status passed and return it
         */
        if ( !is_null($status) ) {
            if ( $status == "open" || $status == "closed" ) {
                $this->doorstatus = $status;
            } 
            /*
             * If the if statement above fails it is an invalid value passed so don't change anything
             * just pass back the current value showing it was unchanged.
             */
        }

        return $this->doorstatus;
    }
    
    function timetofloor() {
        /*
         * Return the time to move from one floor to another
         */
        
        return $this->timetofloor;
    }
    
    function timetoboard() {
        /*
         * Return the time to board, how long the doors are open
         */
        
        return $this->timetoboard;
    }
    
    function status() {
        /*
         * Show the status of the elevator
         */
        if ( $this->isstopped() ) {
            $status = "stopped";
            if ( $this->doorstatus() == "open" ) {
                $status = "boarding/unboarding";
            }
        } else {
            $status = "moving";
        }
        
        return $status;
    }
}

A command line elevator simulator

This repository contains a command line elevator simulator. It is intended to
be run on a Linux host that has the appropriate language installed. This will
probably not run on a Windows host. It uses the clear command to manage the
screen and that does not typically exist on a Windows host.

The primary purpose of the project is to demonstrate object oriented
programming and also event driven programming.

Files:
	README: This file
	runelevator.php:
		This is PHP implementaion that was developed and tested on a
		Fedora 32 host with PHP 7.3.18.
	elevator_php_demo.mp4:
		A short video showing an example of running the simulator

The simulator defaults to a 10 story building. There are two types of
passengers. There are passengers that are in the elevator that want the
elevator to take them to a specific floor. There are also passengers that are
outside the elevator on a particular floor that want to request the elevator
to pick them up to either take them up or down from the floor. The simulator
has a hard coded time of 5 seconds to go from one floor to the next floor. When
it reaches the floor it has a hard coded time of 5 seconds that it holds the
door open for unloading and loading of passengers.

A rider outside the elevator that wants to be picked up from a specific floor
you enter the floor that the rider is currently on, preceded by a "+" if they
want to go up from the floor they are on or "-" if they want to go down.

A rider inside the elevator you only need to enter the floor you want to be
taken to.

Here are some examples:
 +3 - Rider is on floor 3 and wants to be taken to a floor above floor 3
 -3 - Rider is on floor 3 and wants to be taken to a floor below floor 3
 3  - Rider is in elevator and wants to be taken to floor 3

Once the elevator starts in a direction it will continue in that direction
until it stops at a floor and there are no more stop requests in that
direction. If at that point there are stop requests in the opposite direction
the elevator will change directions to satisfy those requests.

All of this means that if the elevator is at floor 1 and a stop request is made
for floor 5 the elevator will move in the up direction to satisfy that stop
request. If while moving and prior to getting to floor 3, an outside rider on
floor 3 wants to go down, entered -3, the elevator will pass by floor 3 on the
way to floor 5. it will stop at floor 5. If no stop requests are made for
floors above floor 5 prior to the door shutting, the elevator will then go down
to floor 3 to satisfy the -3 stop request.

How to run the PHP simulator:

$ php runelevator.php

See the elevator_php_demo.mp4 for an example run.

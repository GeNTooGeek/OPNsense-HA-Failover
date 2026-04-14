# Repository Structure

```
opnsense-ha-failover/
├── README.md								# Main documentation
├── INSTALLATION.md							# Detailed installation guide
├── LICENSE									# MIT License
└── usr/local/share/ha_failover/			# Main script files
    ├── bin/
	│	├── 10-failover.php					# Main failover logic - 755
	│	├── 98-ha_set_routes.php			# Route management helper - 755
	│	├── 99-ha_passive_enforcer.sh		# Boot-time enforcer - 755
	│	└── validate_ha_config.php			# Configuration validator - 755
    ├── conf/                             
	│	└── ha_failover.conf				# Configuration files - 600
	├── etc
	│	├── ha_failover.conf				# symlink to conf
	│	├── rc.d/
	│	│	└── 
	│	└── rc.syshook.d/
	│		├── 98-ha_set_routes.php		# symlink
	│		└── carp/
	│			└── 10-failover.php			# symlink
	└── lib/
		└── failover.inc					# PHP include for failover functions - 600
```

## File Descriptions

### Core Script Files

| File | Location | Purpose | Permissions |
|------|----------|---------|-------------|
| `10-failover.php` | `/usr/local/etc/rc.syshook.d/carp/` | Main CARP event handler | `755` |
| `98-ha_set_routes.php` | `/usr/local/etc/rc.syshook.d/` | Route configuration helper | `755` |
| `99-ha_passive_enforcer.sh` | `/usr/local/etc/rc.d/` | Boot-time backup node enforcer | `755` |
| `validate_ha_config.php` | `/usr/local/etc/` | Configuration validator | `755` |
| `ha_failover.conf` | `/usr/local/etc/` | Central configuration file | `600` |

### Installation Locations

```bash
# Main configuration (secure permissions)
/usr/local/etc/ha_failover.conf                    # 600 (-rw-------)

# Validation utility  
/usr/local/etc/validate_ha_config.php              # 755 (-rwxr-xr-x)

# CARP event handler
/usr/local/etc/rc.syshook.d/carp/10-failover.php   # 755 (-rwxr-xr-x)

# Route management helper
/usr/local/etc/rc.syshook.d/98-ha_set_routes.php   # 755 (-rwxr-xr-x)

# Boot-time service
/usr/local/etc/rc.d/99-ha_passive_enforcer.sh      # 755 (-rwxr-xr-x)

# Runtime files (created automatically)
/tmp/carp_failover.lock                             # Lock file
/tmp/carp_failover.state                            # State tracking
/tmp/carp_failover.failures                         # Failure counter
/var/log/ha_enforcer.log                           # Boot enforcer log
```

### Repository File Manifest

#### Documentation
- `README.md` - Main project documentation and quick start
- `INSTALLATION.md` - Detailed step-by-step installation guide

#### Configuration
- `conf/ha_failover.conf

#### Core Scripts
- `bin/10-failover.php` - Main failover logic (15.6KB)
- `bin/98-ha_set_routes.php` - Route helper (4.2KB) 
- `bin/99-ha_passive_enforcer.sh` - Boot enforcer (3.8KB)
- `bin/validate_ha_config.php` - Config validator (2.1KB)
